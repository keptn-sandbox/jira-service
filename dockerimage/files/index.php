<?php

/*
  This service will:
  - Create JIRA tickets for Keptn `problem.open` events
  - Create JIRA tickets for Keptn `evalution-done` events
  
  -- Labels / Tags --
  JIRA labels will be created for any Keptn labels. JIRA doesn't allow spaces in labels, so spaces are converted to dashes.
  
  If the ticket is an evaluation AND the incoming JSON has a label of `jira_issue` then this evaluation is assumed to be 'caused by' the ticket number referenced in the label.
  This service will link the two tickets with a 'causes / is caused by' relationship.
  
  Additional JIRA labels are always created for `keptn_project`, `keptn_service` and `keptn_stage` values.
  
  When the ticket is an evaluation ticket, a JIRA label for `keptn_result` is created with the value `pass`, `warning` or `fail`.
  This makes it possible to filter using JQL such that: "keptn_project:sockshop AND keptn_result:fail"
  
  -- External Links --
  External links will be added to the tickets as appropriate.
  Problem tickets will have direct links to the Dynatrace problem AND the Keptn's bridge.
  Evaluation tickets will have direct links to the Keptn's bridge.
  
  ** Note: We recommend this service is used in conjunction with the official Dynatrace JIRA plugin: https://marketplace.atlassian.com/apps/1217645/dynatrace-for-jira-cloud?hosting=cloud&tab=overview **
*/

// Create and / or open the log file.
$logFile = fopen("logs/jiraService.log", "a") or die("Unable to open file!");

$jiraBaseURL = getenv("JIRA_BASE_URL");
$jiraUsername = getenv("JIRA_USERNAME");
$jiraAPIToken = getenv("JIRA_API_TOKEN");
$jiraProjectKey = getenv("JIRA_PROJECT_KEY");
$jiraIssueType = getenv("JIRA_ISSUE_TYPE");
$jiraTicketForProblems = getenv("JIRA_TICKET_FOR_PROBLEMS") === 'true'? true : false;
$jiraTicketForEvaluations = getenv("JIRA_TICKET_FOR_EVALUATIONS") === 'true'? true : false;
$dynatraceTenant = getenv("DT_TENANT");
$keptnDomain = getenv("KEPTN_DOMAIN");

if ($jiraBaseURL == null || $jiraUsername == null || $jiraAPIToken == null || $jiraProjectKey == null || $jiraIssueType == null || $keptnDomain == null) {
    fwrite($logFile, "Missing mandatory input parameters JIRA_BASE_URL and / or JIRA_USERNAME and / or JIRA_API_TOKEN and / or JIRA_PROJECT_KEY and / or JIRA_ISSUE_TYPE and / or KEPTN_DOMAIN");
    exit("Missing mandatory input parameters JIRA_BASE_URL and / or JIRA_USERNAME and / or JIRA_API_TOKEN and / or JIRA_PROJECT_KEY and / or JIRA_ISSUE_TYPE and / or KEPTN_DOMAIN");
}

fwrite($logFile, "Got all input variables. Proceeding.\n");

if ($jiraTicketForProblems) fwrite($logFile, "Will create tickets for problems.\n");
else fwrite($logFile, "Will NOT create tickets for problems.\n");
if ($jiraTicketForEvaluations) fwrite($logFile, "Will create tickets for evaluations.\n");
else fwrite($logFile, "Will NOT create tickets for evaluations.\n");

$entityBody = file_get_contents('php://input');

if ($entityBody == null) {
  fwrite($logFile, "Missing data input from Keptn. Exiting.");
  exit("Missing data input from Keptn. Exiting.");
}

// Decode the incoming JSON event
$cloudEvent = json_decode($entityBody);

$eventType = $cloudEvent->{'type'};

fwrite($logFile, "Event Type: $eventType \n");

// Only problem events have a state, so check event state only when it's a problem.open event.
$eventState = "";
if ($eventType == "sh.keptn.event.problem.open") {
  $eventState = $cloudEvent->{'data'}->{'State'};
  fwrite($logFile, "Problem Open Event State : $eventState\n");
}
if ($eventType == "sh.keptn.events.problem") {
  $eventState = $cloudEvent->{'data'}->{'State'};
  fwrite($logFile, "Problem Event State : $eventState\n");
}

/************************************************************
   INPUT PARAM PROCESSING END. START FUNCTION DEFINITIONS.
*************************************************************/

function createJIRATicket($jiraBaseURL, $jiraUsername, $jiraAPIToken, $jiraTicketObj, $ticketType, $cloudEvent, $logFile) {
    
    $keptnDomain = getenv("KEPTN_DOMAIN");
    $dynatraceTenant = getenv("DT_TENANT");
    $jiraBaseURL = "$jiraBaseURL/rest/api/2/issue";
    // Base64 encode the JIRA username and password
    $encodedKey = base64_encode($jiraUsername . ':' . $jiraAPIToken);
    
    $keptnProject = $cloudEvent->{'data'}->{'project'};
    $keptnService = $cloudEvent->{'data'}->{'service'};
    $keptnStage = $cloudEvent->{'data'}->{'stage'};
    $keptnContext = $cloudEvent->{'shkeptncontext'};
    $keptnEventID = $cloudEvent->{'id'};
    $resultLowercase = $cloudEvent->{'data'}->{'result'};
    $bridgeURL = "https://bridge.keptn.$keptnDomain/project/$keptnProject/$keptnService/$keptnContext/$keptnEventID";
    
    // Add description link to Keptn's Bridge
    $jiraTicketObj->fields->description .= "h2. For full output and history, check the [Keptn's Bridge|$bridgeURL].\n";
    
    // Add keptn_* labels
    $labels = array();
    if ($keptnProject != null) array_push($labels, "keptn_project:$keptnProject");
    
    // Add keptn_project label, if present to the ticket body and as a JIRA label.
    if ($keptnService != null) array_push($labels, "keptn_service:$keptnService");
    
    // Add keptn_project label, if present to the ticket body and as a JIRA label.
    if ($keptnStage != null) array_push($labels, "keptn_stage:$keptnStage");
    
    // Create keptn_result label to show "pass", "warning" or "fail" as a label for evaluations.
    if ($ticketType == "EVALUATION") array_push($labels,"keptn_result:$resultLowercase");
    
    // "labels" can be passed via JSON. Add all labels as JIRA labels
    $labelsFromJSON = $cloudEvent->{'data'}->{'labels'};
    if ($labelsFromJSON != null) {
      foreach ($labelsFromJSON as $key => $value) {
        if (is_bool($value)) $value = var_export($value, true); // Transform boolean to string.
        // JIRA doesn't accept whitespace in labels. Replace whitespace with dashes
        $key = str_replace(' ', '-', $key);
        $value = str_replace(' ', '-', $value);
        
        array_push($labels,"$key:$value"); 
      }
    }

    // Add labels to JIRA Object
    if (count($labels) > 0) $jiraTicketObj->fields->labels = $labels;

    $payload = json_encode($jiraTicketObj);
    
    
    //---------------------------------
    //       Create JIRA ticket
    //---------------------------------
    $ch = curl_init($jiraBaseURL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLINFO_HEADER_OUT, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    
    // Set HTTP Header for POST request
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
      'Content-Type: application/json',
      'User-Agent: keptn-jira-service/v1',
      "Authorization: Basic $encodedKey"
    ));
    
    // Submit the POST request
    try {
      $result = curl_exec($ch);
      fwrite($logFile,"Result: $result\n");
    }
    catch (Exception $e) {
        fwrite($logFile, "Exception caught creating ticket. Exiting: $e");
        exit();
    }
    // Close cURL session handle
    curl_close($ch);
    
    //---------------------------------
    // Create link to Keptn's Bridge
    //---------------------------------
    $ticketDetails = json_decode($result);
    $ticketKey = $ticketDetails->{'key'}; // PROJ-123
    
    $jiraRemoteLinkURL = "$jiraBaseURL/$ticketKey/remotelink";
    
    $payloadObj = new stdClass();
    $payloadObj->object->url = $bridgeURL;
    $payloadObj->object->title = "Keptn's Bridge";
    $payloadObj->object->icon->url16x16 = "https://raw.githubusercontent.com/keptn/community/master/logos/keptn-small.png";
    
    $payload = json_encode($payloadObj);
    
    $ch = curl_init($jiraRemoteLinkURL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLINFO_HEADER_OUT, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    
    // Set HTTP Header for POST request
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
      'Content-Type: application/json',
      'User-Agent: keptn-jira-service/v1',
      "Authorization: Basic $encodedKey"
    ));
    
    // Submit the POST request
    try {
      $result = curl_exec($ch);
      fwrite($logFile, "Keptn Bridge Link Result: $result");
    }
    catch (Exception $e) {
        fwrite($logFile, "Exception Caught Creating Keptn Bridge Link: $e");
    }
    
    //---------------------------------
    // Create link to Dynatrace problem
    //---------------------------------
    if ($ticketType == "PROBLEM") {
      if ($dynatraceTenant) {
        $eventPID = $cloudEvent->{'data'}->{'PID'};
        $dynatraceLink = "https://$dynatraceTenant/#problems/problemdetails;pid=$eventPID";
        $jiraTicketObj->fields->description .= "Dynatrace: $dynatraceLink \n";
      }
        $payloadObj = new stdClass();
        $payloadObj->object->url = $dynatraceLink;
        $payloadObj->object->title = "Dynatrace Problem";
        $payloadObj->object->icon->url16x16 = "https://dt-cdn.net/images/favicon-48x48-transparent-48-9b4df9c769.png";
    
        $payload = json_encode($payloadObj);
    
        $ch = curl_init($jiraRemoteLinkURL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    
        // Set HTTP Header for POST request
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
          'Content-Type: application/json',
          'User-Agent: keptn-jira-service/v1',
          "Authorization: Basic $encodedKey"
        ));
    
        // Submit the POST request
        try {
          $result = curl_exec($ch);
          fwrite($logFile, "DT Problem Link Result: $result");
        }
        catch (Exception $e) {
            fwrite($logFile, "Exception Caught Creating DT Problem Link: $e");
        }
      }
      
      //---------------------------------
      // Create link to parent ticket
      //---------------------------------
      
      if ($ticketType == "EVALUATION" && $labelsFromJSON && $labelsFromJSON->jira_issue) {
        
        $jiraParentTicketKeyURL = "$jiraBaseURL/$ticketKey";
        $issueLinkObj = new stdClass();
        $issueLinkObj->add->type->name = "Problem/Incident";
        $issueLinkObj->add->inwardIssue->key = $labelsFromJSON->jira_issue; // Parent issue
        $issueLinks = array($issueLinkObj);

        // Build Ticket Linking Payload
        $payloadObj = new stdClass();
        $payloadObj->update->issuelinks = $issueLinks;
        $payload = json_encode($payloadObj);
        
        $curl = curl_init();

        curl_setopt_array($curl, array(
          CURLOPT_URL => $jiraParentTicketKeyURL,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => "",
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => "PUT",
          CURLOPT_POSTFIELDS => $payload,
          CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'User-Agent: keptn-jira-service/v1',
            "Authorization: Basic $encodedKey"
          ),
        ));
        
        try {
          $response = curl_exec($curl);
          fwrite($logFile, "Link to Parent Ticket Response: $response");
        }
        catch (Exception $e) {
          fwrite($logFile, "Error Linking to Parent Ticket Response: $e");
        }
      }
}

function closeJIRATicket($jiraBaseURL, $jiraUsername, $jiraAPIToken, $cloudEvent, $logFile) {
    fwrite($logFile,"CLOSING TICKET...\n");
    
    $jiraProjectKey = getenv("JIRA_PROJECT_KEY");
    $eventPID = $cloudEvent->{'data'}->{'PID'};
    
    // Base64 encode the JIRA username and password
    $encodedKey = base64_encode($jiraUsername . ':' . $jiraAPIToken);
    
    // Step 1: Search for the issue using PID
    $curl = curl_init();

    curl_setopt_array($curl, array(
      CURLOPT_URL => "$jiraBaseURL/rest/api/2/search",
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "POST",
      CURLOPT_POSTFIELDS =>"{\"jql\":\"project = $jiraProjectKey AND text ~ 'PID: $eventPID'\",\"startAt\":0,\"maxResults\":1,\"fields\":[\"key\"]}",
      CURLOPT_HTTPHEADER => array(
        "Authorization: Basic $encodedKey",
        "Content-Type: application/json"
      ),
    ));
    
    $foundIssueKey = "";
    
    try {
      $response = curl_exec($curl);
      $responseDecoded = json_decode($response);
      $foundIssueKey = $responseDecoded->{'issues'}[0]->{'key'};
      fwrite($logFile, "\nSearch for Ticket by PID Response: $response \n");
    }
    catch (Exception $e) {
      fwrite($logFile, "\n[CLOSE TICKET] Exception caught searching for ticket by PID: $e");
    }
    finally {
      curl_close($curl);
    }
    
    // If no relevant issue found, exit.
    if (!strpbrk($foundIssueKey, $jiraProjectKey)) {
        fwrite($logFile, "[CLOSE TICKET] No relevant issue key for step 1. Found: $foundIssueKey. Exiting. \n");
        die("[CLOSE TICKET] Step 1: No issue found");
    }
    
    // Step 2: Get available transitions for this issue (ie. To retrieve the ID for the "Done" transformation)
    fwrite($logFile, "\n Step 2 JIRA URL: $jiraBaseURL/rest/api/2/issue/$foundIssueKey/transitions \n");
    
    $curl = curl_init();

    curl_setopt_array($curl, array(
      CURLOPT_URL => "$jiraBaseURL/rest/api/2/issue/$foundIssueKey/transitions",
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "GET",
      CURLOPT_HTTPHEADER => array(
        "Authorization: Basic $encodedKey"
      ),
    ));
      
    try {
      $response = curl_exec($curl);
      $responseDecoded = json_decode($response);
      fwrite($logFile, "\n Get transitions for $foundIssueKey response: $response\n");
    }
    catch (Exception $e) {
      fwrite($logFile, "\n[CLOSE TICKET] Exception caught getting available transitions for $foundIssueKey \n");
    }
    finally {
      curl_close($curl);
    }
    
    // Get the transition ID for "Done"
    $transitionID = "";
    foreach ($responseDecoded->{'transitions'} as &$transition) {
      if ($transition->{'name'} == "Done") $transitionID = $transition->{'id'};
    }
    
    fwrite($logFile, "\n [CLOSE TICKET] Transition ID: $transitionID");
    
    // Step 3: Send comment to this issue that we're closing it.
    $curl = curl_init();

    curl_setopt_array($curl, array(
      CURLOPT_URL => "$jiraBaseURL/rest/api/2/issue/$foundIssueKey",
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "PUT",
      CURLOPT_POSTFIELDS =>"{ \"update\": { \"comment\": [{ \"add\": { \"body\": \"Keptn received a problem closed / resolved event. Closing JIRA issue ($foundIssueKey).\" } }] } }",
      CURLOPT_HTTPHEADER => array(
        "Authorization: Basic $encodedKey",
        "Content-Type: application/json"
      ),
    ));

    try {
      $response = curl_exec($curl);
      fwrite($logFile, "\n[CLOSE TICKET] Comment Sent to $foundIssueKey \n");
    }
    catch (Exception $e) {
      fwrite($logFile, "\n[CLOSE TICKET] Comment Sending failed for $foundIssueKey: $e \n");
    }
    finally {
      curl_close($curl);    
    }

    // Step 4: Close the issue
    $curl = curl_init();

    curl_setopt_array($curl, array(
      CURLOPT_URL => "$jiraBaseURL/rest/api/2/issue/$foundIssueKey/transitions",
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "POST",
      CURLOPT_POSTFIELDS =>"{ \"transition\": { \"id\": \"$transitionID\"} }",
      CURLOPT_HTTPHEADER => array(
        "Authorization: Basic $encodedKey",
        "Content-Type: application/json"
      ),
    ));

    try {
      $response = curl_exec($curl);
      fwrite($logFile, "[CLOSE TICKET] Closing ticket for $foundIssueKey. Response: $response");
    }
    catch (Exception $e) {
      fwrite($logFile, "\n[CLOSE TICKET] Closing ticket failed for $foundIssueKey: $e \n");
    }
    finally {
      curl_close($curl);    
    }
}


/*************************************************
*    CREATE TICKET FOR PROBLEM OPEN EVENT
*************************************************/

if ($jiraTicketForProblems && $eventType == "sh.keptn.event.problem.open" && $eventState == "OPEN") {
    
    // Create a JIRA ticket.
    fwrite($logFile, "Got a problem opening event. Creating JIRA ticket.\n");
    
    $eventProblemTitle = $cloudEvent->{'data'}->{'ProblemTitle'};
    $eventImpactedEntity = $cloudEvent->{'data'}->{'ImpactedEntity'};
    $keptnContext = $cloudEvent->{'shkeptncontext'};
    $eventProblemDetails = $cloudEvent->{'data'}->{'ProblemDetails'};
    $eventPID = $cloudEvent->{'data'}->{'PID'};

    $eventProblemID = $cloudEvent->{'data'}->{'ProblemID'};
    $eventTime = $cloudEvent->{'time'};
    $eventTags = "";
    $eventTagsArray = array();

    if ($cloudEvent->{'data'}->{'Tags'} != null)  {
      // Build event tags array by splitting on comma
      $eventTagsArray = explode(',', $eventTags);
    }
    
    fwrite($logFile,"Finished processing problem inputs. Creating JIRA JSON now.\n");
    
    // Build JSON for JIRA
    $jiraTicketObj = new stdClass();
    $jiraTicketObj->fields->project->key = $jiraProjectKey;
    $jiraTicketObj->fields->summary = "[PROBLEM] $eventProblemTitle";
    $jiraTicketObj->fields->description = ""; // Ticket Body goes here...
    $jiraTicketObj->fields->issuetype->name = $jiraIssueType;
    $jiraTicketObj->fields->description .= "$eventImpactedEntity\n\n";
    
    // Print problem details
    $jiraTicketObj->fields->description .= "\n*Problem Details*\n";
    if (is_string($eventProblemDetails)) $jiraTicketObj->fields->description .= "$eventProblemDetails \n";
    else {
      foreach ($eventProblemDetails as $key => $value) {
        if (is_bool($value)) {
          $value = var_export($value, true); // Transform boolean to string.
        }
        // Ignore certain fields.
        $ignore_fields = array("id","startTime", "endTime", "status", "displayName");
        if (in_array($key, $ignore_fields)) continue;
        
        $jiraTicketObj->fields->description .= "$key: $value\n";
      }
    }
   
    // If there are dynatrace tags, pass as a table.
    if (sizeof($eventTagsArray) > 1) {
        $jiraTicketObj->fields->description .= "*Tags*\n";
        $jiraTicketObj->fields->description .= "{noformat}";
        
        foreach ($eventTagsArray as $tag) {
            $jiraTicketObj->fields->description .= "$tag\n";
        }
        $jiraTicketObj->fields->description .= "{noformat}\n";
    }
    
    $jiraTicketObj->fields->description .= "\n*Additional Information*\n";
    $jiraTicketObj->fields->description .= "Time: $eventTime\n";
    $jiraTicketObj->fields->description .= "PID: $eventPID\n";
    
    /* If a dynatrace is used, add a link to the problem ticket.
     * The official JIRA plugin uses this for all sorts of extended functionality
     */
    if ($dynatraceTenant) {
      $dynatraceLink = "https://$dynatraceTenant/#problems/problemdetails;pid=$eventPID";
      $jiraTicketObj->fields->description .= "[Dynatrace Problem|$dynatraceLink] \n";
    }

    fwrite($logFile, "Completed Event processing. Creating ticket now. \n");

    // POST DATA TO JIRA
    createJIRATicket($jiraBaseURL, $jiraUsername, $jiraAPIToken, $jiraTicketObj, "PROBLEM", $cloudEvent, $logFile);
}

/*************************************************
*    MODIFY TICKET FOR PROBLEM CLOSED EVENT
*************************************************/

if ($jiraTicketForProblems && $eventType == "sh.keptn.events.problem" && ($eventState == "CLOSED" || $eventState == "RESOLVED")) {
    closeJIRATicket($jiraBaseURL, $jiraUsername, $jiraAPIToken, $cloudEvent, $logFile);
}

/*************************************************
*  CREATE TICKET FOR PERFORMANCE EVALUATION EVENT
*************************************************/

if ($jiraTicketForEvaluations && $eventType == "sh.keptn.events.evaluation-done") {
    
    // Create JIRA ticket for performance evaluation.
    fwrite($logFile, "Got an evaluation-done event. Create a JIRA ticket. \n");
    
    // Transform Keptn Evaluation Result to uppercase
    $resultLowercase = $cloudEvent->{'data'}->{'result'};
    $result = strtoupper($cloudEvent->{'data'}->{'result'});
    $score = $cloudEvent->{'data'}->{'evaluationdetails'}->{'score'};
    $keptnProject = $cloudEvent->{'data'}->{'project'};
    $keptnService = $cloudEvent->{'data'}->{'service'};
    $keptnStage = $cloudEvent->{'data'}->{'stage'};
    $startTime = $cloudEvent->{'data'}->{'evaluationdetails'}->{'timeStart'};
    $endTime = $cloudEvent->{'data'}->{'evaluationdetails'}->{'timeEnd'};
    $testStrategy = $cloudEvent->{'data'}->{'teststrategy'};

    fwrite($logFile,"Finished processing problem inputs. Creating JIRA JSON now.\n");
    
    // Build JSON for JIRA
    $jiraTicketObj = new stdClass();
    $jiraTicketObj->fields->project->key = $jiraProjectKey;
    $jiraTicketObj->fields->summary = "[EVALUATION] $keptnProject - $keptnService - $keptnStage Result: $result";
    $jiraTicketObj->fields->description = ""; // Ticket Body goes here...
    $jiraTicketObj->fields->issuetype->name = $jiraIssueType;
    
    $jiraTicketObj->fields->description .= "||*Result*||*Score*||\n";
    /* Emojis via API don't follow the UI standard.
     * (/) = :check_mark:
     * (!) = :warning:
     * (x) = :cross_mark:
     */
    if ($result == "PASS") $result = "$result (/)";
    if ($result == "WARNING") $result = "$result (!)";
    if ($result == "FAIL") $result = "$result (x)";
    $jiraTicketObj->fields->description .= "|$result|$score|\n\n";
    $jiraTicketObj->fields->description .= "*Start Time:* $startTime\n";
    $jiraTicketObj->fields->description .= "*End Time:* $endTime\n";
    $jiraTicketObj->fields->description .= "*Test Strategy:* $testStrategy\n\n";
    
    fwrite($logFile, "Completed Event processing. Creating ticket now. \n");
    
    // POST DATA TO JIRA
    createJIRATicket($jiraBaseURL, $jiraUsername, $jiraAPIToken, $jiraTicketObj, "EVALUATION", $cloudEvent, $logFile);
}

// Close handle to log file
fclose($logFile);
?>