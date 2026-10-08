-- Last run of every scheduled cron script (CronRuns::start / finish), shown in the Jobs box on /admin.
-- One row per job name, overwritten on each run. Run on prod with the deploy.
CREATE TABLE IF NOT EXISTS cron_runs (
  name        VARCHAR(64)  NOT NULL,
  started_at  DATETIME     NULL,
  finished_at DATETIME     NULL,
  ok          TINYINT(1)   NOT NULL DEFAULT 0,
  note        VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
