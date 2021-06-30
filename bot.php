<?php

// Setup
error_reporting(E_ALL);
ini_set("log_errors", true);
ini_set("error_log", "logs.txt");

// Environtment Variables
$_ENV = getenv();
if(!isset($_ENV['BOT_TOKEN'])) { error_log("No environment variable BOT_TOKEN found."); die(); }

// Timezone
if(isset($_ENV['TZ'])) {
  if(!date_default_timezone_set($_ENV['TZ'])) error_log("Invalid Timezone!");
}

// Constants
define('BOT_TOKEN', $_ENV['BOT_TOKEN']);
define('API_URL', 'https://api.telegram.org/bot'.BOT_TOKEN.'/');
define('FF_API', '/api/v1/');

// Other Functions
include('functions.php');

// Main Function
function processMessage($message) {
  // process incoming message
  $message_id = $message['message_id'];
  $chat_id = $message['chat']['id'];
  
  // validate the chat id
  if(!file_exists("clients.json")) {
    file_put_contents("clients.json", '');
  }
  $clients = json_decode(file_get_contents("clients.json"), true);
  if(!isset($clients[$chat_id])) {
    $clients[$chat_id] = array();
    $clients[$chat_id]['status'] = 'unregistered';
    saveClients($clients);
  }

  if (isset($message['text'])) {
    // incoming text message
    $text = $message['text'];
    
    if ($clients[$chat_id]['status'] == 'registering..') {
      $clients[$chat_id]['default_account'] = $text;
      $clients[$chat_id]['status'] = 'registered';
      saveClients($clients);
      
      apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => 'Done!, you can start to register your spendings.
To register a spend you just have to send a message with the spend details:
  Amount, Description, Destination Account, Source Account

if you don\' send the  Source Account, the default will be used.

You can also check the balance of your accounts with the command
/accounts

if you pass a date (YYYY-MM-DD) as parameter it will give you the balance for that date considering future spendings.'));

      exit;
    }
    
    if ($clients[$chat_id]['status'] == 'registering.') {
        $clients[$chat_id]['token'] = $text;
        $clients[$chat_id]['status'] = 'registering..';
        saveClients($clients);
      
        $result = ffRequest($clients[$chat_id]['url'], $clients[$chat_id]['token'], 'GET', 'accounts', 'date='.date('Y-m-d').'&type=Asset%20account');
        if(!$result) {
          $clients[$chat_id]['status'] = 'unregistered';
          saveClients($clients);
          apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => 'Accounts could not be fetched, please check the FireflyIII URL and the TOKEN and try the process again.')); exit; 
        }
        $buttons = array();
        $accounts = array();
        foreach($result['data'] as $reg) {
          if($reg['attributes']['active'] == false) continue;
          if($reg['attributes']['account_role'] != 'cashWalletAsset' && $reg['attributes']['account_role'] != 'savingAsset') continue;
          $buttons[] = $reg['attributes']['name'];
        }
               
        apiRequestJson("sendMessage", array('chat_id' => $chat_id, "text" => 'Default account to register the spendings:', 'reply_markup' => array(
          'keyboard' => array($buttons),
          'one_time_keyboard' => true,
          'resize_keyboard' => true)));
    }

    if ($clients[$chat_id]['status'] == 'registering') {
      $clients[$chat_id]['url'] = rtrim($text, '/');
      $clients[$chat_id]['status'] = 'registering.';
      saveClients($clients);
      
      apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => 'Input your FireflyIII token:'));
    }

    if (strpos($text, "/start") === 0) {
    
      if ($clients[$chat_id]['status'] == 'unregistered') {
        $clients[$chat_id]['status'] = 'registering';
        saveClients($clients);
        
        apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => 'URL of your FireflyIII instance:'));
      } 
      
    } else if (strpos($text, "/accounts") === 0 && $clients[$chat_id]['status'] == 'registered') {
      $parameters = explode(' ', $text);
      $date = date('Y-m-d');
      $today = $date;
      
      // recurrences
      $recurrences = array();
      $result = ffRequest($clients[$chat_id]['url'], $clients[$chat_id]['token'], 'GET', 'recurrences');
      foreach($result['data'] as $reg) {
        if($reg['attributes']['active'] == false) continue;
        
        foreach($reg['attributes']['repetitions'][0]['occurrences'] as $occurrence) {
          $recurrence = array();
          $recurrence['date'] = $occurrence;
          $recurrence['title'] = $reg['attributes']['title'];
          
          if($reg['attributes']['type'] == "withdrawal") {
            $recurrence['amount'] = $reg['attributes']['transactions'][0]['amount'] * -1;
            $recurrences[$reg['attributes']['transactions'][0]['source_name']][] = $recurrence;
          } else if($reg['attributes']['type'] == "deposit") {
            $recurrence['amount'] = $reg['attributes']['transactions'][0]['amount'];
            $recurrences[$reg['attributes']['transactions'][0]['destination_name']][] = $recurrence;
          }
          
        }
      }
      
      // accounts
      if(count($parameters) > 1) $date = $parameters[1];
      $result = ffRequest($clients[$chat_id]['url'], $clients[$chat_id]['token'], 'GET', 'accounts', 'date='.$today.'&type=Asset%20account');
      $content = "Balance at ".str_replace("-", "\-", $date)."\n```\n";
      
      $accounts = array();
      foreach($result['data'] as $reg) {
        if($reg['attributes']['active'] == false) continue;
        
        // transactions
        if(str_replace('-', '', $today) < str_replace('-', '', $date)) {
          $resultTransactions = ffRequest($clients[$chat_id]['url'], $clients[$chat_id]['token'], 'GET', 'accounts/'.$reg['id'].'/transactions', 'start='.$today.'&end='.$date);
          foreach($resultTransactions['data'] as $regTransaction) {
            if(substr($regTransaction['attributes']['transactions'][0]['date'], 0, 10) == date('Y-m-d')) continue;
            $transaction = array();
            if($regTransaction['attributes']['transactions'][0]['source_name'] == $reg['attributes']['name'])
              $transaction['amount'] = $regTransaction['attributes']['transactions'][0]['amount'] * -1;
            if($regTransaction['attributes']['transactions'][0]['destination_name'] == $reg['attributes']['name'])
              $transaction['amount'] = $regTransaction['attributes']['transactions'][0]['amount'];
              
            $transaction['date'] = $regTransaction['attributes']['transactions'][0]['date'];
            $transaction['title'] = $regTransaction['attributes']['transactions'][0]['description'];
            
            $recurrences[$reg['attributes']['name']][] = $transaction;
          }
        }

        $contentReccurrence = "";
        $amountRecurrence = 0;
        if(isset($recurrences[$reg['attributes']['name']])) {
          foreach($recurrences[$reg['attributes']['name']] as $recurrence) {
            if(str_replace('-', '', $date) < str_replace('-', '', $recurrence['date'])) continue;

            $contentReccurrence .= str_pad("  ".substr(str_replace(' ', '', $recurrence['title']), 0, 12)." ".substr($recurrence['date'], 5, 5), 20);
            $contentReccurrence .= str_pad(sprintf("%0.2f", $recurrence['amount']), 12, " ", STR_PAD_LEFT);
            $contentReccurrence .= "\n";

            $amountRecurrence += $recurrence['amount'];
          }
        }

        $content .= str_pad($reg['attributes']['name'], 20);
        $content .= str_pad(sprintf("%0.2f", $reg['attributes']['current_balance'] + $amountRecurrence), 12, " ", STR_PAD_LEFT);
        $content .= "\n";
        $content .= $contentReccurrence;
      }
            
      $content .= "```\n";
      apiRequest("sendMessage", array('chat_id' => $chat_id, 'parse_mode' => 'MarkdownV2', "text" => $content));
    } else if($clients[$chat_id]['status'] == 'registered') {
      $parameters = explode(',', $text);
      if(count($parameters) < 3 || count($parameters) > 4) {
        apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => 'The transaction must have at least 3 and no more than 4 parameters divided by coma ","'));
        exit;
      }
      foreach($parameters as &$parameter) $parameter = trim($parameter);
      
      if(count($parameters) < 4) $parameters[] = $clients[$chat_id]['default_account'];
      
      $request = array(
        "error_if_duplicate_hash" => false,
        "apply_rules" => true,
        "transactions" => array(array(
          "type" => "withdrawal",
          "date" => date('Y-m-d', $message['date']).'T'.date('H:i:s', $message['date'])."-05:00",
          "amount" => $parameters[0],
          "description" => $parameters[1],
          "destination_name" => $parameters[2],
          "source_name" => $parameters[3]
        ))
      );
      $result = ffRequest($clients[$chat_id]['url'], $clients[$chat_id]['token'], 'POST', 'transactions', $request);
      if(!$result) { apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => 'The transaction could not be posted, please check the syntaxis.')); exit; }
      apiRequest("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, 'parse_mode' => 'MarkdownV2', "text" => '[Spend Registered\!]('.$clients[$chat_id]['url'].'/transactions/show/'.$result['data']['id'].')'));
    }
  } else {
    apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => 'I understand only text messages'));
  }
}

$content = file_get_contents("php://input");
$update = json_decode($content, true);
if (!$update) {
  // receive wrong update, must not happen
  exit;
}

if (isset($update["message"])) {
  processMessage($update["message"]);
}