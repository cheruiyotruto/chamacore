import {
  defineRailway,
  group,
  postgres,
  preserve,
  project,
  service,
  volume,
} from "railway/iac";

// Railway Infrastructure as Code (the replacement for railway.json).
// Apply with: npm install railway && railway login && railway link \
//   && railway config apply
// See docs/PAAS_DEPLOYMENT.md for the full walkthrough.

export default defineRailway(() => {
  const db = postgres("chama-core-db");
  const storage = volume("chama-storage", { sizeMB: 5120 });

  // Sessions, cache and queue use the database, so no Redis service is needed.
  const common = {
    APP_ENV: "production",
    APP_DEBUG: "false",
    APP_TIMEZONE: "Africa/Nairobi",
    LOG_CHANNEL: "stderr",
    LOG_LEVEL: "info",
    DB_CONNECTION: "pgsql",
    DB_URL: db.env.DATABASE_URL,
    DB_SSLMODE: "require",
    SESSION_DRIVER: "database",
    SESSION_SECURE_COOKIE: "true",
    CACHE_STORE: "database",
    QUEUE_CONNECTION: "database",
    FILESYSTEM_DISK: "local",
    CHAMA_SEED_DEMO: "false",
  };

  const web = service("web", {
    healthcheck: "/up",
    preDeploy:
      "php artisan migrate --force --no-interaction && " +
      "php artisan db:seed --force --no-interaction && " +
      "php artisan optimize",
    volumeMounts: {
      "/var/www/html/storage": storage,
    },
    env: {
      ...common,
      APP_NAME: "Chama Core",
      // Set these once in the Railway dashboard after the first apply.
      APP_KEY: preserve(),
      APP_URL: preserve(),
      SANCTUM_STATEFUL_DOMAINS: preserve(),
      PLATFORM_ADMIN_EMAIL: preserve(),
      PLATFORM_ADMIN_PASSWORD: preserve(),
      TRUSTED_PROXIES: "*",
      // Webhook authentication is mandatory in production; callbacks return
      // 503 until these are set. Use a different long random value for each.
      MPESA_CALLBACK_SECRET: preserve(),
      MPESA_B2B_CALLBACK_SECRET: preserve(),
      BANK_PAYOUT_CALLBACK_SECRET: preserve(),
    },
  });

  const queue = service("worker", {
    start:
      "php artisan queue:work --tries=3 --timeout=120 --sleep=3 --max-time=3600",
    env: {
      ...common,
      APP_KEY: web.env.APP_KEY,
      // Queued jobs build account/invite/confirmation links; without the real
      // public URL they would generate broken http://localhost/... links.
      APP_URL: web.env.APP_URL,
    },
  });

  const scheduler = service("scheduler", {
    start: "php artisan schedule:work --verbose",
    env: {
      ...common,
      APP_KEY: web.env.APP_KEY,
      APP_URL: web.env.APP_URL,
    },
  });

  return project("chama-core", {
    resources: [group("Chama Core", [db, storage, web, queue, scheduler])],
  });
});
