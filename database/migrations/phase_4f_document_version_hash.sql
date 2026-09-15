-- Phase 4F: run once only when the PHP installer cannot be used.
-- The preferred installer is scripts/install_final_readiness_phase_4f.php,
-- which checks the schema first and safely backfills hashes for stored files.

ALTER TABLE `document_versions`
    ADD COLUMN `file_hash` CHAR(64) NULL DEFAULT NULL AFTER `file_path`;
