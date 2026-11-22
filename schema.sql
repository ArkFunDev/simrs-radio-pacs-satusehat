-- Custom tables/columns used by this module (on top of a SIMRS Khanza database).
-- Review before running. Back up your database first.

CREATE TABLE IF NOT EXISTS khanza_pacs_mapping (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  no_rawat       VARCHAR(17)  NOT NULL,
  no_rkm_medis   VARCHAR(15)  DEFAULT NULL,
  nm_pasien      VARCHAR(100) DEFAULT NULL,
  patientid      VARCHAR(30)  DEFAULT NULL,
  acc            VARCHAR(40)  DEFAULT NULL,   -- legacy column (DataPermintaanRadio.php)
  pacs_acc       VARCHAR(40)  DEFAULT NULL,   -- accession number sent to PACS
  pacs_mrn       VARCHAR(30)  DEFAULT NULL,   -- MRN used in PACS
  modality       VARCHAR(10)  DEFAULT NULL,
  procedure_name VARCHAR(100) DEFAULT NULL,
  status         VARCHAR(20)  DEFAULT NULL,   -- 0/1/active/sent/pending/failed
  notes          TEXT,
  dicom_url      TEXT,
  expertise_url  TEXT,
  expertise_text MEDIUMTEXT,
  radname        VARCHAR(100) DEFAULT NULL,
  patient_ihs    VARCHAR(40)  DEFAULT NULL,   -- SatuSehat IHS number
  sr_id          VARCHAR(64)  DEFAULT NULL,   -- SatuSehat ServiceRequest ID (read from Khanza table)
  is_id          VARCHAR(64)  DEFAULT NULL,   -- SatuSehat ImagingStudy ID (set after successful POST)
  studydatetime  DATETIME     DEFAULT NULL,   -- DICOM study date/time -> ImagingStudy.started
  sopclass       VARCHAR(64)  DEFAULT NULL,   -- SOP Class UID (default CR: 1.2.840.10008.5.1.4.1.1.1)
  dokter_rad_ihs VARCHAR(40)  DEFAULT NULL,   -- radiologist's Practitioner IHS -> ImagingStudy.interpreter
  created_at     DATETIME     DEFAULT NULL,
  updated_at     DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_no_rawat (no_rawat),
  KEY idx_pacs_acc (pacs_acc),
  KEY idx_created_at (created_at),
  KEY idx_is_id (is_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS satusehat_servicerequest (
  noorder            VARCHAR(20)  NOT NULL,
  no_rawat           VARCHAR(17)  NOT NULL,
  service_request_id VARCHAR(64)  DEFAULT NULL,
  status             VARCHAR(20)  DEFAULT NULL,
  created_at         DATETIME     DEFAULT NULL,
  updated_at         DATETIME     DEFAULT NULL,
  PRIMARY KEY (noorder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Upgrading an install created from an older schema.sql? Add the columns that
-- imagingstudy.php needs (skip any that already exist; MariaDB supports IF NOT EXISTS):
-- ALTER TABLE khanza_pacs_mapping ADD COLUMN IF NOT EXISTS sr_id          VARCHAR(64) DEFAULT NULL;
-- ALTER TABLE khanza_pacs_mapping ADD COLUMN IF NOT EXISTS is_id          VARCHAR(64) DEFAULT NULL;
-- ALTER TABLE khanza_pacs_mapping ADD COLUMN IF NOT EXISTS studydatetime  DATETIME    DEFAULT NULL;
-- ALTER TABLE khanza_pacs_mapping ADD COLUMN IF NOT EXISTS sopclass       VARCHAR(64) DEFAULT NULL;
-- ALTER TABLE khanza_pacs_mapping ADD COLUMN IF NOT EXISTS dokter_rad_ihs VARCHAR(40) DEFAULT NULL;
-- ALTER TABLE khanza_pacs_mapping ADD KEY idx_created_at (created_at);
-- ALTER TABLE khanza_pacs_mapping ADD KEY idx_is_id (is_id);

-- Used by logActivity() in config.php (silently skipped if missing).
CREATE TABLE IF NOT EXISTS activity_log (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  timestamp DATETIME     NOT NULL,
  user      VARCHAR(100) DEFAULT NULL,
  activity  TEXT,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- NOT created here (they come from SIMRS Khanza's own SatuSehat bridging and are only READ):
--   satu_sehat_encounter                   (id_encounter per no_rawat)
--   satu_sehat_servicerequest_radiologi    (id_servicerequest per noorder)

-- Columns this module writes to periksa_radiologi that may be missing in stock Khanza.
-- Run only the ones your database lacks (check with DESCRIBE periksa_radiologi).
-- ALTER TABLE periksa_radiologi ADD COLUMN status VARCHAR(10) DEFAULT NULL;
-- ALTER TABLE periksa_radiologi ADD COLUMN proyeksi VARCHAR(100) DEFAULT NULL;
-- ALTER TABLE periksa_radiologi ADD COLUMN kV VARCHAR(20) DEFAULT NULL;
-- ALTER TABLE periksa_radiologi ADD COLUMN mAS VARCHAR(20) DEFAULT NULL;
-- ALTER TABLE periksa_radiologi ADD COLUMN FFD VARCHAR(20) DEFAULT NULL;
-- ALTER TABLE periksa_radiologi ADD COLUMN BSF VARCHAR(20) DEFAULT NULL;
-- ALTER TABLE periksa_radiologi ADD COLUMN inak VARCHAR(20) DEFAULT NULL;
-- ALTER TABLE periksa_radiologi ADD COLUMN jml_penyinaran VARCHAR(20) DEFAULT NULL;
-- ALTER TABLE periksa_radiologi ADD COLUMN dosis VARCHAR(20) DEFAULT NULL;