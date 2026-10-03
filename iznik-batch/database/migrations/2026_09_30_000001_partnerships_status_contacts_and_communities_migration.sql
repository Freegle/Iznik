-- Idempotent production SQL for 2026_09_30_000001_partnerships_status_contacts_and_communities.php
--
-- Partnerships: a pipeline status replaces the agreed flag, a renewal traffic light, the
-- price before bulk discount, several contacts per deal, and a per-community source
-- (inside the boundary / added by hand / left out by hand) with the boundary overlap.
-- Run once on production BEFORE deploying the iznik-server-go and iznik-batch code that uses it.
-- The partnerships tables are small (tens of rows), so none of this is slow.

DROP PROCEDURE IF EXISTS partnerships_2026_09_30;
DELIMITER //
CREATE PROCEDURE partnerships_2026_09_30()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
                   AND table_name = 'partnerships' AND column_name = 'status') THEN
        ALTER TABLE partnerships
            ADD COLUMN status ENUM('Quoted','InPrinciple','Confirmed','Paid','Overdue') NOT NULL DEFAULT 'Quoted' AFTER amount,
            ADD COLUMN renewal ENUM('Likely','Unsure','Unlikely') NULL AFTER status,
            ADD COLUMN fullprice DECIMAL(10,2) NULL COMMENT 'Price before any bulk discount, as quoted to the council' AFTER renewal;

        IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
                   AND table_name = 'partnerships' AND column_name = 'agreed') THEN
            UPDATE partnerships SET status = 'Confirmed' WHERE agreed = 1;
        END IF;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE()
                   AND table_name = 'partnerships_contacts') THEN
        CREATE TABLE partnerships_contacts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            partnershipid BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NULL,
            email VARCHAR(255) NULL,
            role ENUM('Waste','Finance','Other') NOT NULL DEFAULT 'Waste',
            KEY partnershipid (partnershipid),
            CONSTRAINT partnerships_contacts_partnershipid_foreign FOREIGN KEY (partnershipid)
                REFERENCES partnerships (id) ON DELETE CASCADE
        ) COMMENT='People at the council we deal with; all of them get the statistics';

        IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
                   AND table_name = 'partnerships' AND column_name = 'contactemail') THEN
            INSERT INTO partnerships_contacts (partnershipid, name, email, role)
                SELECT id, contactname, contactemail, 'Waste' FROM partnerships
                WHERE COALESCE(contactname, '') != '' OR COALESCE(contactemail, '') != '';
        END IF;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
               AND table_name = 'partnerships' AND column_name = 'agreed') THEN
        ALTER TABLE partnerships DROP COLUMN agreed, DROP COLUMN agreeddate,
            DROP COLUMN contactname, DROP COLUMN contactemail;
    END IF;

    -- partnerships_groups is dropped with the group model; only touch it where it still exists.
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE()
               AND table_name = 'partnerships_groups')
       AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
                   AND table_name = 'partnerships_groups' AND column_name = 'source') THEN
        ALTER TABLE partnerships_groups
            ADD COLUMN source ENUM('Boundary','Added','Removed') NOT NULL DEFAULT 'Boundary' AFTER groupid,
            ADD COLUMN overlap DECIMAL(5,4) NULL COMMENT 'Fraction of the community inside the council boundary' AFTER source;
    END IF;
END //
DELIMITER ;

CALL partnerships_2026_09_30();
DROP PROCEDURE partnerships_2026_09_30;
