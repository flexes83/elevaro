<?php
return [
  'enabled' => true,
  'to' => 'felix.kuester@me.com',
  'from' => 'quiz@'.($_SERVER['HTTP_HOST'] ?? 'localhost'),
  'subject_prefix' => '[Lernquiz]',
];
