-- Run once on the TRIMS database (adjust schema name if needed).

IF COL_LENGTH('dbo.TRIMS_TBL_INSPECTION', 'HBL') IS NULL
    ALTER TABLE dbo.TRIMS_TBL_INSPECTION ADD HBL VARCHAR(128) NULL;
