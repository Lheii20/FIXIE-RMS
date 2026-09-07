<?php
/** Reusable e-signature asset include. Add this after sidebar.php on approval pages. */
?>
<link rel="stylesheet" href="assets/css/e-signature.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/e-signature.css') ? filemtime(__DIR__ . '/../assets/css/e-signature.css') : '1'; ?>">
<script src="assets/js/e-signature.js?v=<?php echo file_exists(__DIR__ . '/../assets/js/e-signature.js') ? filemtime(__DIR__ . '/../assets/js/e-signature.js') : '1'; ?>"></script>
