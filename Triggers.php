
DELIMITER //

CREATE TRIGGER after_postmeta_insert
AFTER INSERT ON wpfu_postmeta
FOR EACH ROW
BEGIN
    IF NEW.meta_key LIKE '%wpcargo_shipper_phone%' THEN
        INSERT INTO wpfu_shipment_log (shipment_id) VALUES (NEW.post_id);
    END IF;
END //
DELIMITER ;


DELIMITER //

CREATE TRIGGER after_postmeta_update
AFTER UPDATE ON wpfu_postmeta
 FOR EACH ROW 
 BEGIN
    IF NEW.meta_key LIKE '%wpcargo_status%' THEN
        INSERT INTO wpfu_shipment_update (shipment_id) VALUES (NEW.post_id);
    END IF;
END //
DELIMITER ;

SELECT * FROM wpys_postmeta WHERE meta_key LIKE '%wpcargo_shipper_phone%'   ORDER BY `meta_id` DESC;

