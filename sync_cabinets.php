<?php
require 'config/db_connect.php';

// 1. Siguraduhing may Default Building at Room
$conn->query("INSERT IGNORE INTO virt_buildings (id, name) VALUES (1, 'Main Office')");
$conn->query("INSERT IGNORE INTO virt_rooms (id, building_id, name) VALUES (1, 1, 'Records Room')");

// 2. I-clear ang mga lumang cabinets at drawers para malinis ang pag-sync
$conn->query("SET FOREIGN_KEY_CHECKS = 0;");
$conn->query("TRUNCATE TABLE virt_cabinets");
$conn->query("TRUNCATE TABLE virt_drawers");
$conn->query("UPDATE document_categories SET drawer_id = NULL");
$conn->query("SET FOREIGN_KEY_CHECKS = 1;");

// 3. Kunin ang lahat ng unique na Main Folders (Parent Categories)
$query = $conn->query("SELECT DISTINCT parent_category FROM document_categories WHERE parent_category != ''");

$count = 0;
if ($query) {
    while($row = $query->fetch_assoc()) {
        $parent = $row['parent_category'];

        // 4. Gawan ng sariling Cabinet ang bawat Main Folder
        $cab_name = "Cabinet: " . $parent;
        $stmt_cab = $conn->prepare("INSERT INTO virt_cabinets (room_id, name) VALUES (1, ?)");
        $stmt_cab->bind_param("s", $cab_name);
        $stmt_cab->execute();
        $cabinet_id = $stmt_cab->insert_id;

        // 5. Gawan ng "Drawer 1" ang bawat bagong Cabinet
        $drawer_name = "Drawer 1";
        $stmt_draw = $conn->prepare("INSERT INTO virt_drawers (cabinet_id, name) VALUES (?, ?)");
        $stmt_draw->bind_param("is", $cabinet_id, $drawer_name);
        $stmt_draw->execute();
        $drawer_id = $stmt_draw->insert_id;

        // 6. I-link ang lahat ng sub-folders ng Main Folder na ito sa bagong Drawer
        $stmt_upd = $conn->prepare("UPDATE document_categories SET drawer_id = ? WHERE parent_category = ?");
        $stmt_upd->bind_param("is", $drawer_id, $parent);
        $stmt_upd->execute();
        
        $count++;
    }
}

echo "<h3>Sync Complete!</h3>";
echo "<p>Successfully created <strong>{$count}</strong> unique Cabinets for each Main Folder.</p>";
echo "<p>You can now delete this file (sync_cabinets.php) for security purposes.</p>";
echo "<a href='virtual_cabinet.php'>Go to Virtual Cabinet</a>";
?>