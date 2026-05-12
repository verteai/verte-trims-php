-- Run once on QA_FAB_INSP (adjust schema name if needed).
-- Adds separate vessel fields to inspection table; drop legacy combined column after migrating data.

IF COL_LENGTH('dbo.TRIMS_TBL_INSPECTION', 'Vessel') IS NULL
    ALTER TABLE dbo.TRIMS_TBL_INSPECTION ADD Vessel VARCHAR(128) NULL;

IF COL_LENGTH('dbo.TRIMS_TBL_INSPECTION', 'Voyage') IS NULL
    ALTER TABLE dbo.TRIMS_TBL_INSPECTION ADD Voyage VARCHAR(128) NULL;

IF COL_LENGTH('dbo.TRIMS_TBL_INSPECTION', 'Container_Num') IS NULL
    ALTER TABLE dbo.TRIMS_TBL_INSPECTION ADD Container_Num VARCHAR(128) NULL;

-- Optional: copy old combined text into Vessel then drop old column:
-- UPDATE dbo.TRIMS_TBL_INSPECTION SET Vessel = Vessel_Voyage WHERE Vessel IS NULL AND Vessel_Voyage IS NOT NULL;
-- ALTER TABLE dbo.TRIMS_TBL_INSPECTION DROP COLUMN Vessel_Voyage;
