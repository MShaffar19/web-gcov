<?php

if ($argc != 6) 
{
	die("cron.php requires 5 arguments: [tmp] [out] [phpsrc] [makestatus] [phpversion]\n\n");
}

define('CRON_PHP',true);

$tmpdir  = $argv[1];	// Temporary storage directory for this PHP version
$outdir  = $argv[2];	// Output directory for this PHP version (set, but only used in master)
$phpdir  = $argv[3];	// Directory where the PHP build source files are located
$makestatus = $argv[4]; // Make status from bash script (fail or pass)
$phpver = $argv[5];	// The version identifier for this PHP build (i.e. PHP_4_4)

$workdir = dirname(__FILE__); // Get the working directory to simplify php file includes

// Initialize core variables
$build_time = -1;	// Total time required for build (build_time.php)

$totalnumerrors = 0; 	// Total number of errors (compile_errors.php)
$totalnumwarnings = 0;	// Total number of warnings (compile_errors.php)

$totalnumleaks = 0;	// Total number of memory leaks (valgrind.php)
$totalnumfailures = 0;	// Total number of test failures (tests.php)

$configureinfo = 'N/A';	// Information regarding configure (system.php)

$compilerinfo = 'N/A';	// Information regarding compiler (system.php)

$osinfo = 'N/A';	// Information regarding operating system (system.php)

$valgrindinfo = 'N/A'; // Information regarding valgrind (system.php)

$codecoverage_percent = -1; // Information regarding the code coverage

$version_id = 0;	// Start by assuming the version_id is unknown

$xmlarray = array();

// Set up variables that apply to all scripts
$version = basename($phpdir, __FILE__); // todo: make this dynamic or based on the tags instead

// Load main configuration including database connection
require $workdir.'/config.php';

// Load templates used by the various components of the cron scripts
require $workdir.'/template.php';

// Gathers the key system information
require $workdir.'/system.php';

// Processes the log file to find compile related errors and warnings
require $workdir.'/compile_results.php';

// This section is required for either system configuration
if($makestatus == 'pass')
{

	$data  = file_get_contents("$tmpdir/php_test.log");

	// If file could not be opened we should track the error
	if($data === false)
	{
		echo basename($_SERVER['PHP_SELF']).": it appears the build process has succeeded but the PHP test log file at $tmpdir/php_test.log could not be opened for processing.  If the problem persists, you may want to check the permissions for the cron scripts in the temporary directory to ensure that the user that runs the cron scripts has at least read and write access for the directory $tmpdir and all files contained within this directory.\n";
	}
	else
	{
		// Check for unicode (is this sufficient for PHP > 6?)
		if(preg_match('/UNICODE[ ]*:[ ]*ON[ ]?/', $data))
		{
			// Easy way to track that unicode is enabled
			$unicode = true;
		}

		// Run the PHP tests
		require $workdir.'/tests.php';
		// Run the valgrind code
		require $workdir.'/valgrind.php';
		// Get the time it took to create the build
		require $workdir.'/build_time.php';
	}
} // End check for pass make status for both client and server

// This file can be used as a reference in the future for the build status
file_put_contents($tmpdir.DIRECTORY_SEPARATOR.'last_make_status.inc', $makestatus);

// Start Master Only Section //
if($is_master)
{
	// Get version ID for the current PHP version
	try
	{
		$sql = 'SELECT version_id FROM versions WHERE version_name = ?';
		$stmt = $mysqlconn->prepare($sql);
		$stmt->execute(array($phpver));
		$version_id = $stmt->fetchColumn();
	}
	catch(PDOException $e)
	{
		// if error occurs this might be good to log
		$version_id = 0;
	}

	// If version > 0 then we have a valid PHP version
	if($version_id > 0)
	{
		// Add new build to the build tables
		$build_date = date('Y-m-d');
		$build_datetime = date('Y-m-d H-i-s');
	
		// This data is used mainly for the graph generation
		$stmt = null;
		$sql = 'INSERT INTO local_builds (build_id, version_id, build_date, build_datetime, build_numerrors, build_numwarnings, build_numfailures, build_numleaks, build_percent_code_coverage, build_os_info, build_compiler_info) '.
		'VALUES (NULL, :version_id, :build_date, :build_datetime, :build_numerrors, :build_numwarnings, :build_numfailures, :build_numleaks, :build_percent_code_coverage, :build_os_info, :build_compiler_info) ';
		$stmt = $mysqlconn->prepare($sql);

		$stmt->bindParam(':version_id', $version_id);
		$stmt->bindParam(':build_date', $build_date);
		$stmt->bindParam(':build_datetime', $build_datetime);
		$stmt->bindParam(':build_numerrors', $totalnumerrors);
		$stmt->bindParam(':build_numwarnings', $totalnumwarnings);
		$stmt->bindParam(':build_numfailures', $totalnumfailures);
		$stmt->bindParam(':build_numleaks', $totalnumleaks);
		$stmt->bindParam(':build_percent_code_coverage', $codecoverage_percent);
		$stmt->bindParam(':build_os_info', $osinfo);
		$stmt->bindParam(':build_compiler_info', $compilerinfo);
		$stmt->execute();
		
		$stmt = null;

		// Graphs will be generated and the database updated with the latest build information
		if($makestatus == 'pass')
		{			
			//if(date('D') == 'Sun')
			//{
				$graph_mode = 'weekly';
				require $workdir.'/graph.php';
			//}

			//if(date('d') == 1)
			//{
				$graph_mode = 'monthly';
				require $workdir.'/graph.php';
			//}

			// Do SQL updates for the specific PHP version
			$sql = 'UPDATE versions SET version_last_build_time=?, version_last_attempted_build_date=?, version_last_successful_build_date=? WHERE version_id=?';

			$stmt_arr = array($build_time, $build_datetime, $build_datetime, $version_id);
		} // End check makestatus was a pass
		else
		{
			// If build fails only update the last attempted build date for the version

			$sql = 'UPDATE versions SET version_last_attempted_build_date = ? WHERE version_id = ?';
			$stmt_arr = array($build_datetime, $version_id);

		} // End check makestatus failed

		$stmt = $mysqlconn->prepare($sql);
		$stmt->execute($stmt_arr);

		// Update the existing version information
		echo 'Version Build Time: '.$build_time."\n";
		echo 'Version Code Coverage: '.$codecoverage_percent.'%'."\n";

		// Only the master server should have the last make status outputted to the outdir
		file_put_contents($outdir.DIRECTORY_SEPARATOR.'last_make_status.inc', $makestatus);

	} // End check for version > 0

} // End Master Only Section
else
{
	// Start Client Only Section //

	// At this point the client system would start generating the XML for transmission
	$xml_out = '<?xml version="1.0" encoding="UTF-8"?>';
	
	$xml_out .= <<< XML

<build>
<buildinfo>
<username>$server_submit_user</username>
<version>$phpver</version>
<buildstatus>$makestatus</buildstatus>
<buildtime>$build_time</buildtime>
<codecoverage>$codecoverage_percent</codecoverage>
<compiler>$compilerinfo</compiler>
<configure>$configureinfo</configure>
<os>$osinfo</os>
<valgrind>$valgrindinfo</valgrind>
</buildinfo>
<builddata>
XML;

	// Ensure compile results have been added to the array before adding to the XML file
	if(isset($xmlarray['compile_results']))
	{
		$xml_out .= <<< XML
<compile_results>
XML;

		foreach($xmlarray['compile_results'] as $res)
		{
			$xml_out .= <<< XML
			
<message file="$res[file]" function="$res[function]" line="$res[line]" type="$res[type]">$res[msg]</message>
XML;
		} // End loop for the compile results array
	
    $xml_out .= <<< XML
</compile_results>
XML;

	} // End check for compile results definition

	// Ensure tests have been added to the array before adding to the XML file
	if(isset($xmlarray['tests']))
	{
		$xml_out .= <<< XML
<tests>
XML;

		foreach($xmlarray['tests'] as $test)
		{
			// todo: should title be included for passed tests?
			$xml_out .= <<< XML
<test status="$test[status]" file="$test[file]" type="$test[testtype]">
XML;

			// Since more info is available for failed tests
			if(strtolower($test['status']) == 'fail')	
			{
				$title = htmlspecialchars($test['title']);
				$script = htmlspecialchars($test['script']);
				$diff = base64_encode($test['difference']);
				$exp = base64_encode($test['expected']);
				$out = base64_encode($test['output']);

				// Include the content specific to a test failure to the XML section
				$xml_out .= <<< XML
<title>$title</title>
<script><![CDATA[$script]]></script>
<difference>$diff</difference>
<expected>$exp</expected>
<output>$out</output>
XML;

			} // End additional output for failed tests

			$xml_out .= <<< XML
</test>
XML;
		} // End loop for the tests array

		$xml_out .= <<< XML
</tests>
XML;

	} // End check for tests definition

	// Ensure memory leaks have been added to the array before adding to the XML file
	if(isset($xmlarray['valgrind']))
	{
		$xml_out .= <<< XML
<valgrind>
XML;

		foreach($xmlarray['valgrind'] as $valgrind)
		{	
			$title = htmlspecialchars($valgrind['title']);

			// Add leak information to the XML file
			$xml_out .= <<< XML
<leak file="$valgrind[file]" type="$valgrind[testtype]">
<title>$title</title>
<script><![CDATA[{$valgrind[script]}]]></script>
<report><![CDATA[{$valgrind[report]}]]></report>
</leak>
XML;
		} // End loop through each valgrind memory leak

		$xml_out .= <<< XML
</valgrind>
XML;
	} // End check for valgrind leaks  definition 

	// End XML
	$xml_out .= <<< XML
</builddata>
</build>
XML;

	if(file_put_contents($tmpdir.'/build.xml', $xml_out))
	{
		echo 'XML file written'."\n";
	}

	// Setup for data post to remote server
	$contents = bzcompress($xml_out);
	$contents = base64_encode($contents);

	// Set up the post data
	$postdata = array('username' => $server_submit_user,
        	                'password' => md5($server_submit_pass),
                	        'contents' => $contents
	        );


	$result = file_get_contents($server_submit_url, false, 
		stream_context_create(
			array('http' =>
				array(
				'method'=>'POST',
				'headers'=> 'Content-type: application/x-www-form-urlencoded', 
				'content' => http_build_query($postdata)
				)
			)
		)
	);

	if($result === false)
		echo 'Not posted.  Please try again in a few minutes, or check to verify the server address is correct.';
	else
    echo "Results posted to server, the following is the server response.\n".$result;
	echo "\n";
} // End of check for client instance

?>