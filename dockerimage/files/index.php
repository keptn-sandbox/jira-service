<?php

$jiraBaseURL = getenv("JIRA_BASE_URL");
$jiraUsername = getenv("JIRA_USERNAME");
$jiraAPIToken = getenv("JIRA_API_TOKEN");
$jiraProjectKey = getenv("JIRA_PROJECT_KEY");
$jiraIssueType = getenv("JIRA_ISSUE_TYPE");

// Write the raw input to the log file...
$logFile = fopen("logs/jiraService.log", "a") or die("Unable to open file!");

if ($jiraBaseURL == null || $jiraUsername == null || $jiraAPIToken == null || $jiraProjectKey == null || $jiraIssueType == null) {
    fwrite($logFile, "Missing mandatory input parameters JIRA_BASE_URL and / or JIRA_USERNAME and / or JIRA_API_TOKEN and / or JIRA_PROJECT_KEY and / or JIRA_ISSUE_TYPE");
    exit("Missing mandatory input parameters JIRA_BASE_URL and / or JIRA_USERNAME and / or JIRA_API_TOKEN and / or JIRA_PROJECT_KEY and / or JIRA_ISSUE_TYPE");
}

$entityBody = file_get_contents('php://input');

if ($entityBody == null) {
  fwrite($logFile, "Missing data input from Keptn. Exiting.");
  exit("Missing data input from Keptn. Exiting.");
}

//Decode the incoming JSON event
$cloudEvent = json_decode($entityBody);

// Transform Keptn Evaluation Result to uppercase
$result = strtoupper($cloudEvent->{'data'}->{'result'});

$keptnProject = $cloudEvent->{'data'}->{'project'};
$keptnService = $cloudEvent->{'data'}->{'service'};
$keptnStage = $cloudEvent->{'data'}->{'stage'};

// Build JSON for JIRA
$jiraTicketObj = new stdClass();
$jiraTicketObj->fields->project->key = $jiraProjectKey;
$jiraTicketObj->fields->summary = "Keptn Evaluation Result: $result";
$jiraTicketObj->fields->description = ""; // Ticket Body goes here...
$jiraTicketObj->fields->issuetype->name = $jiraIssueType;

$jiraTicketObj->fields->description .= "h1. Test Details\n\n";
$jiraTicketObj->fields->description .= "Project: " . $keptnProject . "\n";
$jiraTicketObj->fields->description .= "Service: " . $keptnService . "\n";
$jiraTicketObj->fields->description .= "Stage: " . $keptnStage . "\n";

// For loop through indicatorResults
$jiraTicketObj->fields->description .= "h1. SLI Results \n\n";
foreach ($cloudEvent->{'data'}->{'evaluationdetails'}->{'indicatorResults'} as &$value) {
  $jiraTicketObj->fields->description .= "|| *Metric* || *Status* || *Value* ||\n";
  $jiraTicketObj->fields->description .= "| " . $value->{'value'}->{'metric'} . " | " . $value->{'status'} . " | " . $value->{'value'}->{'value'} ." |\n\n";
  $jiraTicketObj->fields->description .= "h1. Targets \n\n";
  $jiraTicketObj->fields->description .= "|| *Criteria* || *Violated* ||\n";
  
  foreach ($value->{'targets'} as &$target) {
      $jiraTicketObj->fields->description .= "| " . $target->{'criteria'} . " | " . ($target->{'violated'} ? 'true' : 'false') ." |\n";
  }
  
  $jiraTicketObj->fields->description .= "\n\n----\n\n";
  
  if ($value->{'value'}->{'message'} != "") {
    $jiraTicketObj->fields->description .= "Message: " . $value->{'value'}->{'message'} . "\n\n";
  }
}

$jiraTicketObj->fields->description .= "Keptn Context: " . $cloudEvent->{'shkeptncontext'};

$jiraJSON = json_encode($jiraTicketObj);

$jiraBaseURL = "$jiraBaseURL/rest/api/2/issue";

/******************************
   POST DATA TO JIRA
******************************/

// Base64 encode the JIRA username and password
$encodedKey = base64_encode($jiraUsername . ':' . $jiraAPIToken);

$ch = curl_init($jiraBaseURL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLINFO_HEADER_OUT, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jiraJSON);

// Set HTTP Header for POST request
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
  'Content-Type: application/json',
  "Authorization: Basic $encodedKey"
));

// Submit the POST request
$result = curl_exec($ch);

//echo $result;

// Close cURL session handle
curl_close($ch);

fclose($logFile);
?>