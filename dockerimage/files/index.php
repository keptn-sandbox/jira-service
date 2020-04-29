<?php

// Write the raw input to the log file...
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

//Decode the incoming JSON event
$cloudEvent = json_decode($entityBody);

$eventType = $cloudEvent->{'type'};

fwrite($logFile, "Event Type: $eventType \n");

// Only problem events have a state, so check event state only when it's a problem.open event.
$eventState = "";
if ($eventType == "sh.keptn.event.problem.open") $eventState = $cloudEvent->{'data'}->{'State'};

if ($eventType == "sh.keptn.event.problem.open") fwrite($logFile, "Problem Event State (this can be CLOSED or OPEN): $eventState\n");

/************************************************************
   INPUT PARAM PROCESSING END. START FUNCTION DEFINITIONS.
*************************************************************/

function createJIRATicket($jiraBaseURL, $jiraUsername, $jiraAPIToken, $jiraTicketObj, $ticketType, $cloudEvent, $logFile) {
    
    $keptnDomain = getenv("KEPTN_DOMAIN");
    $dynatraceTenant = getenv("DT_TENANT");
    $jiraBaseURL = "$jiraBaseURL/rest/api/2/issue";
    // $ticketType is either: "PROBLEM" or "EVALATION"
    
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
    if (count($labels) > 0) {
      $jiraTicketObj->fields->labels = $labels;
    }

    $payload = json_encode($jiraTicketObj);
    
    // Base64 encode the JIRA username and password
    $encodedKey = base64_encode($jiraUsername . ':' . $jiraAPIToken);
    
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
    
    // Create link to Keptn's Bridge
    
    $ticketDetails = json_decode($result);
    $ticketKey = $ticketDetails->{'key'}; // PROJ-123
    
    $jiraBaseURL = "$jiraBaseURL/$ticketKey/remotelink";
    
    $payloadObj = new stdClass();
    $payloadObj->object->url = $bridgeURL;
    $payloadObj->object->title = "Keptn's Bridge";
    $payloadObj->object->icon->url16x16 = "https://raw.githubusercontent.com/keptn/community/master/logos/keptn-small.png";
    
    $payload = json_encode($payloadObj);
    
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
      fwrite($logFile, "Keptn Bridge Link Result: $result");
    }
    catch (Exception $e) {
        fwrite($logFile, "Exception Caught Creating Keptn Bridge Link: $e");
    }
    
    // Create link to Dynatrace problem
    if ($ticketType == "PROBLEM") {
      if ($dynatraceTenant) {
        $eventPID = $cloudEvent->{'data'}->{'PID'};
        $dynatraceLink = "https://$dynatraceTenant/#problems/problemdetails;pid=$eventPID";
        $jiraTicketObj->fields->description .= "Dynatrace: $dynatraceLink \n";
        
        $payloadObj = new stdClass();
        $payloadObj->object->url = $dynatraceLink;
        $payloadObj->object->title = "Dynatrace Problem";
        $payloadObj->object->icon->url16x16 = "https://dt-cdn.net/images/favicon-48x48-transparent-48-9b4df9c769.png";
    
        $payload = json_encode($payloadObj);
    
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
          fwrite($logFile, "DT Problem Link Result: $result");
        }
        catch (Exception $e) {
            fwrite($logFile, "Exception Caught Creating DT Problem Link: $e");
        }
      }
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

if ($jiraTicketForProblems && $eventType == "sh.keptn.event.problem.open" && $eventState == "CLOSED") {
    
    // Modify JIRA ticket.
    fwrite($logFile, "Got a problem closed event. NOT YET IMPLEMENTED. \n");
    fwrite($logFile, "$entityBody \n");
    // NOT YET IMPLEMENTED
    // Problem is closing. Process the JIRA ticket.
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
    $endTime = $cloudEvent->{'data'}->{'evaluationdetails'}->{'timeStart'};
    $testStrategy = $cloudEvent->{'data'}->{'teststrategy'};

    fwrite($logFile,"Finished processing problem inputs. Creating JIRA JSON now.\n");
    
    // Build JSON for JIRA
    $jiraTicketObj = new stdClass();
    $jiraTicketObj->fields->project->key = $jiraProjectKey;
    $jiraTicketObj->fields->summary = "[EVALUATION] $keptnProject - $keptnService - $keptnStage Result: $result";
    $jiraTicketObj->fields->description = ""; // Ticket Body goes here...
    $jiraTicketObj->fields->issuetype->name = $jiraIssueType;
    
    $jiraTicketObj->fields->description .= "||*Result*||*Score*||\n";
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