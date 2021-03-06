<?php
///////////////////////////////////////////////////
// Paradise ~ centerkey.com/paradise             //
// GPLv3 ~ Copyright (c) individual contributors //
///////////////////////////////////////////////////

// REST Web Services
//
// Example read resource:
//    GET/HTTP gallery/console/rest?type=gallery
// Update value:
//    GET/HTTP gallery/console/rest?type=settings&action=update&caption-italic=true
//
// Type       Action
// ---------  ------
// security   login, create
// command    process-uploads, generate-gallery
// settings   get, update
// gallery    get
// portfolio  get, update, delete, list
// account    list
// invite     list, create
//
// Note:
//    Query parameters are used instead of path parameters to avoid the need for
//    URL (.htaccess) configuration.

$noAuth = true;
require "../php/security.php";
require "../php/image-processing.php";

function restError($code) {
   $messages = array(
      400 => "Invalid parameters",
      401 => "Unauthorized access",
      404 => "Resource not found",
      500 => "Unknown error",
      501 => "Not implemented"
      );
   return array(
      "error"   => true,
      "code"    => $code,
      "message" => $messages[$code]
      );
   }

function test() {  //url: http://localhost/paradise-test/gallery/console/rest?type=command&action=test
   return array("test" => true);
   }

function runCommand($action) {
   if ($action == "test")
      $resource = test();
   elseif ($action === "process-uploads")
      $resource = processUploads();
   elseif ($action === "generate-gallery")
      $resource = generateGalleryDb();
   else
      $resource = restError(400);
   return $resource;
   }

function fieldValue($value, $type) {
   $value = iconv("UTF-8", "UTF-8//IGNORE", $value);
   $value = str_replace("<", "&lt;", str_replace(">", "&gt;", $value));
   if ($type === "boolean")
      $value = $value === "true";
   elseif ($type === "integer")
      $value = intval($value);
   return $value;
   }

function updateItem($resource, $itemType) {
   if ($itemType === "page") {
      $item = $resource->pages[fieldValue($_GET["id"], "integer") - 1];
      if (isset($_GET["title"]))
         $item->title = fieldValue($_GET["title"], "string");
      if (isset($_GET["show"]))
         $item->show = fieldValue($_GET["show"], "boolean");
      }
   }

function updateSettings() {
   $fields = array(
      "title" =>          "string",
      "title-font" =>     "string",
      "title-size" =>     "string",
      "subtitle" =>       "string",
      "footer" =>         "string",
      "caption-caps" =>   "boolean",
      "caption-italic" => "boolean",
      "cc-license" =>     "boolean",
      "bookmarks" =>      "boolean",
      "contact-email" =>  "string"
      );
   $resource = readSettingsDb();
   if (isset($_GET["item"]))
      updateItem($resource, $_GET["item"]);
   else
      foreach ($fields as $field => $type)
         if (isset($_GET[$field]))
            $resource->{$field} = fieldValue($_GET[$field], $type);
   return saveSettingsDb($resource);
   }

function updatePortfolio($id) {
   $fields = array(
      "sort" =>        "integer",
      "display" =>     "boolean",
      "caption" =>     "string",
      "description" => "string",
      "badge" =>       "string"
      );
   $resource = readPortfolioImageDb($id);
   if ($resource) {
      foreach ($fields as $field => $type)
         if (isset($_GET[$field]))
            $resource->{$field} = fieldValue($_GET[$field], $type);
      $move = $_GET["move"];
      if ($move)
         $resource->sort = calcNewPortfolioSort($resource->sort, $move === "up");
      savePortfolioImageDb($resource);
      generateGalleryDb();
      }
   return $resource ?: restError(404);
   }

function deletePortfolio($id) {
   $resource = readPortfolioImageDb($id);
   if (!$_SESSION["read-only-user"] && $resource) {
      deleteImages($id);
      generateGalleryDb();
      }
   return $resource ?: restError(404);
   }

function restRequestSettings($action) {
   return $action === "update" ? updateSettings() : readSettingsDb();
   }

function restRequestGallery() {
   return readGalleryDb();
   }

function restRequestPortfolio($action, $id) {
   $actions = array(
      "create" => function($id) { return restError(400); },
      "get" =>    function($id) { return restError(501); },
      "update" => function($id) { return updatePortfolio($id); },
      "delete" => function($id) { return deletePortfolio($id); },
      "list" =>   function($id) { return readPortfolioDb(); }
      );
   return $actions[$action]($id);
   }

function restRequestAccount($action, $email) {
   return array_keys(get_object_vars(readAccountsDb()->users));
   }

function resource($loggedIn) {
   $routes = array(
      "settings" =>  function($action) { return restRequestSettings($action); },
      "gallery" =>   function($action) { return restRequestGallery(); },
      "portfolio" => function($action) { return restRequestPortfolio($action, $_GET["id"]); },
      "account" =>   function($action) { return restRequestAccount($action, $_GET["email"]); },
      "invite" =>    function($action) { return restRequestInvite($action, $_GET["email"]); },
      );
   $type =   $_GET["type"];
   $action = $_GET["action"] ?: "get";
   $_GET["email"] = strtolower($_GET["email"]);
   $standardAction = in_array($action, array("create", "get", "update", "delete", "list"));
   if ($type === "security")
      $resource = restRequestSecurity($action,
         $_POST["email"], $_POST["password"], $_POST["confirm"], $_POST["invite"]);
   elseif (!$loggedIn)
      $resource = restError(401);
   elseif ($type === "command")
      $resource = runCommand($action);
   elseif (isset($routes[$type]) && $standardAction)
      $resource = $routes[$type]($action);
   else
      $resource = restError(400);
   logEvent("get-resource", $type, $action, $_GET["id"], !getProperty($resource, "error"));
   return $resource;
   }

httpJsonResponse(resource($loggedIn));
?>
