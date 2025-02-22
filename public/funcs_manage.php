<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/exception.php';
require_once __DIR__ . '/funcs_common.php';

/**
 * Inserts a message to the management log.
 */
function funcs_manage_log(string $message) {
  $ip = funcs_common_get_client_remote_address(MB_CLOUDFLARE, $_SERVER);

  insert_log($ip, time(), $_SESSION['mb_username'], $message);
}

/**
 * Checks user login credentials and on success assigns session variables.
 */
function funcs_manage_login(array $account, string $password): bool {
  // reject if passwords do not match
  if (funcs_common_verify_password($password, $account['password']) !== TRUE) {
    return false;
  }

  // set session variables
  $_SESSION['mb_username'] = $account['username'];
  $_SESSION['mb_role'] = $account['role'];

  funcs_manage_log('Logged in');

  return true;
}

/**
 * Returns true if user is logged in by checking session vars.
 */
function funcs_manage_is_logged_in(): bool {
  return isset($_SESSION['mb_username']) && isset($_SESSION['mb_role']);
}

/**
 * Destroys user session variables.
 */
function funcs_manage_logout(): bool {
  funcs_manage_log('Logged out');

  // destroy session variables and return success code
  return session_unset();
}

/**
 * Gets user role from session if set.
 */
function funcs_manage_get_role(): int|null {
  if (isset($_SESSION['mb_role'])) {
    return $_SESSION['mb_role'];
  }

  return null;
}

/**
 * Imports data from another MySQL/MariaDB database.
 */
function funcs_manage_import(array $params): string {
  funcs_manage_log("Executed import, source db: {$params['db_name']}, source table: {$params['table_name']}, target board: {$params['board_id']}");

  // handle each table type separately
  $inserted = 0;
  $warnings = [];
  switch ($params['table_type']) {
    case MB_IMPORT_TINYIB_ACCOUNTS:
      // execute import
      $inserted = insert_import_accounts_tinyib($params, $params['table_name']);
      break;
    case MB_IMPORT_TINYIB_POSTS:
      // validate params
      if (!array_key_exists($params['board_id'], MB_BOARDS)) {
        $warnings[] = "Target BOARD id '{$params['board_id']}' not found";
      } else {
        // init auto increment table
        init_post_auto_increment($params['board_id']);
  
        // execute import
        $inserted = insert_import_posts_tinyib($params, $params['table_name'], $params['board_id']);
  
        // refresh auto increment table
        refresh_post_auto_increment($params['board_id']);
      }
      break;
    default:
      $warnings[] = "Unsupported table_type '{$params['table_type']}'";
      break;
  }

  // collect warnings
  $warnings = implode('<br>  - ', $warnings);

  $status = "Imported {$inserted} rows";
  if (strlen($warnings) > 0) {
    $status .= "<br>Warnings:<br>- {$warnings}";
  }
  funcs_manage_log($status);
  return $status;
}

/**
 * Rebuilds all data in the database.
 */
function funcs_manage_rebuild(array $params): string {
  funcs_manage_log("Executed rebuild, target board: {$params['board_id']}");

  // get board config
  $board_cfg = funcs_common_get_board_cfg($params['board_id']);

  // select posts
  $posts = select_rebuild_posts($params['board_id']);

  // rebuild each post
  $processed = 0;
  $total = count($posts);
  $warnings = [];
  foreach ($posts as &$post) {
    // process fields
    $name = $post['name'] !== '' ? $post['name'] : $board_cfg['anonymous'];
    $email = $post['email'];
    $message = $post['message'];
    
    // do extra cleanup for imported data because of raw HTML
    if ($post['imported']) {
      $name = funcs_common_clean_field($name);
      $email = funcs_common_clean_field($email);
      $message = strip_tags($message);
      $message = htmlspecialchars_decode($message, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401);
    }

    // render nameblock and message
    $nameblock = funcs_board_render_nameblock($name, $post['tripcode'], $email, $post['role'], $post['timestamp']);
    $message = funcs_board_render_message($params['board_id'], $message, $board_cfg['truncate']);

    // render file
    $file = $post['file'];
    if ($post['embed'] === 1) {
      $file = rawurlencode($file);
    }

    // update post
    $rebuild_post = [
      'post_id' => $post['post_id'],
      'board_id' => $post['board_id'],
      'message_rendered' => $message['rendered'],
      'message_truncated' => $message['truncated'],
      'nameblock' => $nameblock,
      'file_rendered' => $file
    ];
    if (!update_rebuild_post($rebuild_post)) {
      $warnings[] = "Failed to rebuild post /{$post['board_id']}/{$post['post_id']}/";
    }

    $processed++;
  }

  // collect warnings
  $warnings = implode('<br>  - ', $warnings);

  $status = "Rebuilt {$processed}/{$total} posts";
  if (strlen($warnings) > 0) {
    $status .= "<br>Warnings:<br>- {$warnings}";
  }
  funcs_manage_log($status);
  return $status;
}

/**
 * Deletes all selected posts from filesystem and database.
 */
function funcs_manage_delete(array $select): string {
  funcs_manage_log('Executed delete, target posts: ' . implode(', ', $select));

  // delete each post and replies
  $processed = 0;
  $total = 0;
  $warnings = [];
  foreach ($select as $val) {
    // parse board id and post id
    $selected_parsed = explode('/', $val);
    $selected_board_id = $selected_parsed[0];
    $selected_post_id = intval($selected_parsed[1]);

    // select post with replies
    $selected_posts = select_post_with_replies($selected_board_id, $selected_post_id);
    $total += count($selected_posts);

    foreach ($selected_posts as &$post) {
      // is the file thumbnail static?
      $static = str_contains($post['thumb'], '/static/');

      // count identical files, only unlink if this is the last one
      $file_collisions = select_files_by_md5($post['file_hex']);
      $file_collisions_n = count($file_collisions);

      // unlink file and thumb from filesystem
      if ($file_collisions_n === 1) {
        if ($post['embed'] === 0 && strlen($post['file']) > 0) {
          if (!unlink(__DIR__ . $post['file'])) {
            $warnings[] = "Failed to delete file for post /{$post['board_id']}/{$post['post_id']}/ (maybe it didn't exist?)";
          }
        }
        
        if (!$static && strlen($post['thumb']) > 0) {
          if (!unlink(__DIR__ . $post['thumb'])) {
            $warnings[] = "Failed to delete thumbnail for post /{$post['board_id']}/{$post['post_id']}/ (maybe it didn't exist?)";
          }
        }
      }

      // delete post from db
      if (!delete_post($post['board_id'], $post['post_id'], true)) {
        $warnings[] = "Failed to delete post /{$post['board_id']}/{$post['post_id']}/ from db";
      }

      // debump if deleted post was a reply
      if ($post['parent_id'] > 0) {
        $thread_bumped = bump_thread($post['board_id'], $post['parent_id']);
      }

      $processed++;
    }
  }

  // collect warnings
  $warnings = implode('<br>  - ', $warnings);

  $status = "Deleted {$processed}/{$total} posts";
  if (strlen($warnings) > 0) {
    $status .= "<br>Warnings:<br>- {$warnings}";
  }
  funcs_manage_log($status);
  return $status;
}

function funcs_manage_approve(array $select): string {
  funcs_manage_log('Executed approve, target posts: ' . implode(', ', $select));

  // delete each report
  $processed = 0;
  foreach ($select as $val) {
    // parse board id and post id
    $selected_parsed = explode('/', $val);
    $selected_board_id = $selected_parsed[0];
    $selected_post_id = intval($selected_parsed[1]);

    // delete all reports by board id and post id
    $processed += delete_reports_by_post_id($selected_board_id, $selected_post_id);
  }

  $status = "Approved {$processed} reports";
  funcs_manage_log($status);
  return $status;
}

function funcs_manage_toggle_lock(array $select): string {
  funcs_manage_log('Executed toggle_lock, target posts: ' . implode(', ', $select));

  // lock/unlock each post
  $processed = 0;
  foreach ($select as $val) {
    // parse board id and post id
    $selected_parsed = explode('/', $val);
    $selected_board_id = $selected_parsed[0];
    $selected_post_id = intval($selected_parsed[1]);

    // lock/unlock all posts by board id and post id
    $processed += toggle_post_locked($selected_board_id, $selected_post_id);
  }

  $status = "Toggled lock state for {$processed} posts";
  funcs_manage_log($status);
  return $status;
}

function funcs_manage_toggle_sticky(array $select): string {
  funcs_manage_log('Executed toggle_sticky, target posts: ' . implode(', ', $select));

  // sticky/unsticky each post
  $processed = 0;
  foreach ($select as $val) {
    // parse board id and post id
    $selected_parsed = explode('/', $val);
    $selected_board_id = $selected_parsed[0];
    $selected_post_id = intval($selected_parsed[1]);

    // sticky/unsticky all posts by board id and post id
    $processed += toggle_post_stickied($selected_board_id, $selected_post_id);
  }

  $status = "Toggled sticky state for {$processed} posts";
  funcs_manage_log($status);
  return $status;
}
