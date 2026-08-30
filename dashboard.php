<?php
require 'config.php';
requireLogin();
if (isAdmin()) { header('Location: admin.php'); exit; }
header('Location: dashboard.php?tab=create');
// TEMP - full file push in next commit
