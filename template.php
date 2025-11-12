<?php
use Preauth\Env;
global $auth;
$env = new Env();
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
      width: 100%;
    }
    body {
      display: table-cell;
      vertical-align: middle;
    }
    h1 {
      font-size: 2.5em;
      font-weight: normal;
      text-align: center;
    }
    form {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
    }
    form div {
      width: 45%;
    }
    div.right {
      text-align: right;
    }
    div.center {
      text-align: center;
    }
  </style>
</head>
<body>
  <h1><?php echo $env->getTitle(); ?></h1>
  <form action="/" method="get">
    <input type="hidden" name="rt" value="<?php echo $auth->getReturnTo(); ?>">
    <div class="right"><label for="id"><?php echo $env->getIdName(); ?>:</label></div>
    <div><input type="text" name="id" id="id" autocomplete="on" required="required" autofocus="autofocus"></div>
    <div class="right"><label for="token"><?php echo $env->getTokenName(); ?>:</label></div>
    <div><input type="text" name="token" id="token" autocomplete="off" required="required"></div>
    <div class="center"><button type="submit"><?php echo $env->getSubmitName(); ?></button></div>
  </form>
</body>
</html>
