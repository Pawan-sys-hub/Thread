<?php
require_once '../db.php';
session_destroy();
jsonResponse(['success' => true, 'message' => 'Logged out.']);
