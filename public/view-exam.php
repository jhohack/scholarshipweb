<?php
// Redirect to the correct Admin page
header("Location: ../admin/view-exam.php?id=" . $_GET['id']);
exit();
?>