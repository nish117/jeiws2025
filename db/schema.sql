-- ============================================================
-- JEIWS site-ops schema — labour attendance & materials stock
-- MySQL / MariaDB (matches the shared hosting target)
-- Run once against the target database, e.g.:
--   mysql -u jeiws_app -p jeiws < db/schema.sql
-- ============================================================

SET NAMES utf8mb4;

-- Site users (separate from the single shared CMS admin password).
-- Each site user logs in with their own username + password and is
-- assigned to one or more projects by an admin.
CREATE TABLE IF NOT EXISTS site_users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(100) NOT NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Mirror of data/projects.json (id + title only). The CMS remains the
-- source of truth for project content; admin/functions.php keeps this
-- table in sync on save/delete so it can be used as a foreign key
-- anchor here without duplicating gallery/description data.
CREATE TABLE IF NOT EXISTS projects (
    id         VARCHAR(64)  PRIMARY KEY,
    title      VARCHAR(255) NOT NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which site users can log data against which projects.
CREATE TABLE IF NOT EXISTS user_projects (
    user_id     INT         NOT NULL,
    project_id  VARCHAR(64) NOT NULL,
    assigned_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, project_id),
    FOREIGN KEY (user_id)    REFERENCES site_users(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Global worker roster, reusable across projects.
CREATE TABLE IF NOT EXISTS workers (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    full_name  VARCHAR(100) NOT NULL,
    category   VARCHAR(50),              -- e.g. Mason, Helper, Carpenter, Electrician
    daily_wage DECIMAL(10,2),
    phone      VARCHAR(20),
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per worker per project per day.
CREATE TABLE IF NOT EXISTS labour_attendance (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    project_id       VARCHAR(64) NOT NULL,
    worker_id        INT         NOT NULL,
    attendance_date  DATE        NOT NULL,
    status           VARCHAR(10) NOT NULL CHECK (status IN ('present','absent','half_day')),
    notes            TEXT,
    recorded_by      INT,
    created_at       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_attendance (project_id, worker_id, attendance_date),
    FOREIGN KEY (project_id)  REFERENCES projects(id)   ON DELETE CASCADE,
    FOREIGN KEY (worker_id)   REFERENCES workers(id)    ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES site_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_attendance_project_date ON labour_attendance(project_id, attendance_date);

-- Global material catalog, reusable across projects.
CREATE TABLE IF NOT EXISTS materials (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,   -- e.g. "Reinforcement 12mm" — include distinguishing
                                          -- specs (like rebar diameter) directly in the name so
                                          -- each variant gets its own tracked stock line
    category   VARCHAR(50),              -- e.g. Reinforcement, Cement, Sand, Aggregate, Bricks
    unit       VARCHAR(20)  NOT NULL,   -- bags, kg, cft, truckload, pcs...
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_material (name, unit)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stock movement ledger — running balance per project/material is
-- derived by summing IN and subtracting OUT (see computeStockTotals()
-- in site/functions.php), giving full history rather than just a count.
CREATE TABLE IF NOT EXISTS materials_stock (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    project_id  VARCHAR(64)   NOT NULL,
    material_id INT           NOT NULL,
    txn_type    VARCHAR(3)    NOT NULL CHECK (txn_type IN ('in','out')),
    quantity    DECIMAL(12,2) NOT NULL CHECK (quantity > 0),
    bundle_qty  DECIMAL(12,2) NULL,       -- optional secondary count, e.g. reinforcement
                                            -- bundles alongside the primary kg quantity
    txn_date    DATE          NOT NULL,
    notes       TEXT,
    recorded_by INT,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id)  REFERENCES projects(id)   ON DELETE CASCADE,
    FOREIGN KEY (material_id) REFERENCES materials(id)  ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES site_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_stock_project_material ON materials_stock(project_id, material_id);
