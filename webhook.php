<?php

/* Function to create logger to be updated everytime the script runs */
function createLogger($file_name)
{
    $req_date = date('Y-m-d H:i:s');
    file_put_contents($file_name, "\n*******************TIMESTAMP : $req_date *********************\n", FILE_APPEND);

    return function ($message) use ($file_name) {
        file_put_contents($file_name, $message, FILE_APPEND);
    };
}

// Initiate logger
$logger = createLogger('log.txt');

// Get contents coming from webhook call
$input = file_get_contents('php://input');

// Decode data from json to human readable format
$data = json_decode($input, true);

// Check if company name is passed in the form, if not, the code exits and doesn't create a clickup task
$companyName = $data['Company'] ?? '';
if (empty($companyName)) {
    $errorMessage = "Company name not provided";
    $logger($errorMessage . "\nData Sent: " . json_encode($data) . "\n");
    $logger("Terminating script due to missing company name.\n");
    header('Content-Type: application/json');
    echo json_encode(["error" => $errorMessage]);
    exit;
}

// API credentials, all tasks created from the webhook will show created by the owner of the API key
$clickupApiKey = ''; // Insert your Clickup API key

// Define headers
$headers = [
    'Authorization: ' . $clickupApiKey,
    'Content-Type: application/json'
];

// Store the ids of the required templates, mapped to the folders the new tasks should be created in, and the standard name of the task in template dictionaries
$templates = [
    ['template_id' => '', 'folder_id' => '', 'task_name' => "[" . $companyName . "] Daily CC C&B"],
    ['template_id' => '', 'folder_id' => '', 'task_name' => "[" . $companyName . "] CC Build"]
];

// Store links to the clickup tasks to be sent back to the GHL automation as part of the confirmation message
$taskLinks = [];


/* 
Function to create tasks from given templates
Accepts a template dictionary, headers, and a logger
*/
function createTaskFromTemplate($template, $headers, $logger)
{
    $url = "https://api.clickup.com/api/v2/folder/{$template['folder_id']}/list_template/{$template['template_id']}";
    // Configure the body of the request to set the name of the task
    $postData = ['name' => $template['task_name']];

    // Make request with curl
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));

    $response = curl_exec($ch);
    curl_close($ch);

    $logger("Response from creating task: $response\n");

    $result = json_decode($response, true);

    // Getting the task ID to build the task URL
    if (isset($result['id'])) {
        $taskId = $result['id'];
        $link = "https://app.clickup.com/t/$taskId";
        $logger("Task created successfully: $link\n");
        return $link;
    } else {
        $logger("Error creating task from template {$template['template_id']}\n");
        return null;
    }
}


// Loop through all templates defined above and call the function to create tasks using predefined templates
foreach ($templates as $template) {
    $link = createTaskFromTemplate($template, $headers, $logger);
    if ($link) {
        $taskLinks[] = $link;
    }
}

// Write final results to log file
$logger("Tasks created successfully for $companyName\n");
$logger("Task Links: " . implode(", ", $taskLinks) . "\n");


// Return response to GHL webhook
header('Content-Type: application/json');
echo json_encode([
    'message' => "Tasks created successfully for $companyName",
    'task_links' => $taskLinks
]);

