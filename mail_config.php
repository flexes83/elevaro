<?php
return [
  'enabled' => false,
  'to' => '',
  'from' => 'quiz@'.($_SERVER['HTTP_HOST'] ?? 'localhost'),
  'subject_prefix' => '[Elevaro]',
];
