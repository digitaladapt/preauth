<?php
use Preauth\Env;
global $auth;
$env = new Env();
header("http/1.1 {$env->getDeniedCode()} {$env->getDeniedTitle()}", true, $env->getDeniedCode());
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?php echo $env->getTitle(); ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Begin Icons -->
  <link rel="apple-touch-icon" sizes="180x180" href="https://<?php echo $auth->getBaseDomain(); ?>/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="https://<?php echo $auth->getBaseDomain(); ?>/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="192x192" href="https://<?php echo $auth->getBaseDomain(); ?>/android-chrome-192x192.png">
  <link rel="icon" type="image/png" sizes="16x16" href="https://<?php echo $auth->getBaseDomain(); ?>/favicon-16x16.png">
  <link rel="manifest" href="https://<?php echo $auth->getBaseDomain(); ?>/site.webmanifest">
  <meta name="apple-mobile-web-app-title" content="<?php echo $env->getTitle(); ?>">
  <meta name="application-name" content="<?php echo $env->getTitle(); ?>">
  <meta name="msapplication-TileColor" content="<?php echo $env->getColor(); ?>">
  <meta name="theme-color" content="<?php echo $env->getColor(); ?>">
  <!-- End Icons -->
  <style>
    * {
      margin: 0;
      padding: 0.25em;
    }
    html {
      background-color: <?php echo $env->getColor(); ?>;
      color: <?php echo $env->getTextColor(); ?>;
      display: table;
      font-family: sans-serif;
      font-size: 1.5em;
      height: 100%;
      padding: 0;
      text-align: center;
      width: 100%;
    }
    body {
      display: table-cell;
      vertical-align: middle;
    }
    h1 {
      font-size: 2.5em;
      font-weight: normal;
    }
  </style>
</head>
<body>
  <h1><?php echo $env->getTitle(); ?></h1>
  <h2><?php echo $env->getDeniedTitle(); ?></h2>
  <p><?php echo $env->getDeniedMessage(); ?></p>
</body>
</html>
