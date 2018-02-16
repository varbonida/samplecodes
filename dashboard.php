<?php
defined('BASEPATH') OR exit('No direct script access allowed');

Class Dashboard extends CI_Controller
{
	var $response_code = 1; //false
	var $reponse_message = "";
	var $user_email = "";
	var $emp_id = 0;
  var $position = 0;
  var $department = 0;

	public function __construct()
	{
		parent::__construct();
		$this->user_email = $this->session->userdata('emp_email');
		$this->emp_id = $this->session->userdata('emp_id');
		$this->position = $this->session->userdata('emp_position');
        $this->department = $this->session->userdata('emp_department');

		$this->load->model('Login_model', 'login');
		$this->load->model('Dashboard_model', 'dashboard');
		$this->load->model('Team_model', 'team');

		authenticateAccess();
		checkIfDAS();
		checkIfSales();
	}

	public function index()
	{
		$data['title'] = "Dashboard";
		$service_url = '';
		$user_email = $this->user_email;

		/** ACCESS CURL TO THE JIRA API **/
		$email = 'sample@email.com';
		$pass  = base64_decode('samplePass43'); //decode the encoded jira password
		/** END **/

		if($this->session->userdata('logged_in') != 1)
		{
			$data['records'] = $this->dashboard->getInProgressTicketsPerUser(); //query existing data

			if(!empty($data['records']))
			{
				$service_url = 'https://companysupport.atlassian.net/rest/api/2/search?jql=assignee='.'"'.$user_email.'"%20AND%20Updated>=startOfDay()%20AND%20Updated<=endOfDay()%20AND%20status%20not%20in%20(%22Task%20Closed%22,%22Site%20is%20live%22,%22Revisions%20Complete%22)%20ORDER%20BY%20cf[12100]%20DESC&startAt=0&maxResults=1000';
				//get tickets created within the day if db entries are not empty (this does not inclde the task closed, site is live and revisions complete tickets)
			}
			else
			{
				$service_url = 'https://companysupport.atlassian.net/rest/api/2/search?jql=assignee='.'"'.$user_email.'"%20AND%20status%20not%20in%20(%22Task%20Closed%22,%22Site%20is%20live%22,%22Revisions%20Complete%22)%20ORDER%20BY%20cf[12100]%20DESC&startAt=0&maxResults=1000';
				//get all tickets if not record yet
			}

			/** Perform CURL to JIRA API **/
			$curl = curl_init();
			curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
			curl_setopt($curl, CURLOPT_USERPWD, "$email:$pass");
			curl_setopt($curl, CURLOPT_URL, $service_url);
			curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
			curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($curl, CURLOPT_ENCODING, '');
			$curl_response = curl_exec($curl);
			$responses = json_decode($curl_response);
			/** END CURL **/

			/** Save-Update entries to our database **/
			$entries = array();

			foreach($responses->issues as $resp)
			{
				$date_created = new DateTime($resp->fields->created);
				$date_updated = new DateTime($resp->fields->updated);
				$date_created->setTimezone(new DateTimeZone('Asia/Singapore'));
				$date_updated->setTimezone(new DateTimeZone('Asia/Singapore'));
				$status;

				if($resp->fields->status->name != "Task Closed" || $resp->fields->status->name != "Site is live")
				{
					$status = 0; // ticket not closed
				}
				if($resp->fields->status->name == "Task Closed" || $resp->fields->status->name == "Site is live")
				{
					$status = 5; // ticket is already closed
				}

				$ticket_id = $resp->key; //jira ticket id
				$retain_ticket_status = $this->dashboard->get_ticket_status($ticket_id);

				$insert_data = array(
					'prod_added_by'					=> $this->session->userdata('emp_id'),
					'prod_ticket_ID' 				=> $ticket_id,
					'prod_ticket_Jira_Link' 		=> "https://companysupport.atlassian.net/browse/".$resp->key,
					'prod_dmc_email'  				=> $resp->fields->reporter->emailAddress,
					'prod_builder_email' 			=> $resp->fields->assignee->emailAddress,
					'prod_biz_name'					=> $resp->fields->summary,
					'prod_priority' 				=> $resp->fields->priority->name,
					'prod_salesforce' 				=> $resp->fields->customfield_12001,
					'prod_appointment' 				=> $resp->fields->customfield_12100,
					'prod_jira_status' 				=> $resp->fields->status->name,
					'prod_date_added'				=> $date_created->format('Y-m-d H:i:s'),
					'prod_date_updated'				=> $date_updated->format('Y-m-d H:i:s')
				);
				$inserted = $this->dashboard->insert_or_update('prod_tracker', $ticket_id, $insert_data);

				//insert to separate table for ticket logs/history
				/*
				$insert_logs_data = array(
					'track_ticket_id'					=> $ticket_id,
					'track_assignee_email' 				=> $resp->fields->assignee->emailAddress,
					'track_updated_on' 					=> $resp->fields->assignee->emailAddress,
					'track_assigned_on'					=> date('Y-m-d H:i:s')
				);
				$inserted = $this->dashboard->insert_or_update('prod_tracker', $ticket_id, $insert_data);
				*/
			}
			curl_close($curl);
		}

		$this->session->set_userdata('logged_in', 1); //updated session, user already loggedin

		$data['specials'] = $this->dashboard->getSpecialProjects();
		$this->load->view('Dashboard/index', $data);
	}


	/** cURL Helper to determine count of kickbacks and time duration spent in each tickets **/
	public function getTicketLogs()
	{
		$jira_ticket_id = $this->input->post("ticket_id");

		/** ACCESS CURL TO THE JIRA API **/
		$email = 'sample@email.com';
		$pass  = base64_decode('samplePass43'); //decode the encoded jira password
		/** END **/

		$service_url = 'https://companysupport.atlassian.net/rest/api/2/issue/'.$jira_ticket_id.'?expand=changelog&fields=""'; //get worklogs per issues

		/** Perform CURL to JIRA API **/
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($curl, CURLOPT_USERPWD, "$email:$pass");
		curl_setopt($curl, CURLOPT_URL, $service_url);
		curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($curl, CURLOPT_ENCODING, '');
		$curl_response = curl_exec($curl);
		$responses = json_decode($curl_response);
		/** END CURL **/

		/** Logic to count kickbacks in each issue and the duration spent for each **/
		$responses_length = count($responses->changelog->histories);
		$kickback_counter = 0;
		$kickback_reason = "";
		for($count = 0; $count < $responses_length; $count++)
		{
			/** remove unused objects in the array **/
			unset($responses->expand);
			unset($responses->id);
			unset($responses->self);
			unset($responses->key);
			/** End **/

			$from_qc_inprogress = strtolower($responses->changelog->histories[$count]->items[0]->fromString); //check if it's from QC Assigned
			$to_task_assigned = strtolower($responses->changelog->histories[$count]->items[0]->toString); //check if from QC Assigned to Task Assigned - Kickback

			if($from_qc_inprogress == strtolower("QC in Progress") && $to_task_assigned == strtolower("Task Assigned"))
			{
				$kickback_counter = $kickback_counter + 1; //count number of kickbacks (from qc assigned to task assigned)
			}
		}
		echo $kickback_counter;
		/** End **/
	}


	/** cURL Helper to determine count of kickbacks and time duration spent in each tickets **/
	public function getTimeSpent()
	{
		$jira_ticket_id = $this->input->post("ticket_id");

		/** ACCESS CURL TO THE JIRA API **/
		$email = 'sample@email.com';
		$pass  = base64_decode('samplePass43'); //decode the encoded jira password
		/** END **/

		$service_url = 'https://companysupport.atlassian.net/rest/api/2/issue/'.$jira_ticket_id.'?expand=changelog&fields=""'; //get worklogs per issues

		/** Perform CURL to JIRA API **/
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($curl, CURLOPT_USERPWD, "$email:$pass");
		curl_setopt($curl, CURLOPT_URL, $service_url);
		curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($curl, CURLOPT_ENCODING, '');
		$curl_response = curl_exec($curl);
		$responses = json_decode($curl_response);
		/** END CURL **/

		/** Logic to count kickbacks in each issue and the duration spent for each **/
		$responses_length = count($responses->changelog->histories);
		$count = 0;
		$timespent = 0;
		$dates_merged = "";
		$formatted_time_spent_duration = "";

		for($count = 0; $count < $responses_length; $count++)
		{
			/** remove unused objects in the array **/
			unset($responses->expand);
			unset($responses->id);
			unset($responses->self);
			unset($responses->key);
			/** End **/

			$fromstring = strtolower($responses->changelog->histories[$count]->items[0]->fromString);
			$tostring = strtolower($responses->changelog->histories[$count]->items[0]->toString);

			if($fromstring == strtolower("Task Assigned") && $tostring == strtolower("Task In Progress") || $fromstring == strtolower("Task In Progress") && $tostring == strtolower("Task in QC"))
			{
				$date_created = new DateTime($responses->changelog->histories[$count]->created);
				$dates_merged .= $date_created->format('Y-m-d H:i:s').",";
			}
		}

		$trimmed_date_merged = rtrim($dates_merged, ",");
		$date_trimmed_arr = explode(",", $trimmed_date_merged);
		$chunked_dates_arr = array_chunk($date_trimmed_arr, 2);

		foreach($chunked_dates_arr as $chunked)
		{
			$formatted_from_date_started = new DateTime($chunked[0]);
			$formatted_from_date_started->format('Y-m-d H:i:s');

			$formatted_end_date_started = new DateTime($chunked[1]);
			$formatted_end_date_started->format('Y-m-d H:i:s');

			if(!empty($chunked[1]))
			{
				$time_spent_duration = date_diff($formatted_from_date_started, $formatted_end_date_started);
				$formatted_time_spent_duration .= "<i class='fa fa-clock-o'></i> ".$time_spent_duration->format('%hh %im %ss')."<br />";
			}
			else
			{
				$time_spent_duration = date_diff($formatted_from_date_started, $formatted_from_date_started);
				$formatted_time_spent_duration .= ""."<br />";
			}
		}
		$trimmed_total_duration = rtrim($formatted_time_spent_duration, "<br />");
		echo $trimmed_total_duration;
		/** End **/
	}

	/** Process displaying of data on datatables **/
	public function ajaxInProgressTickets()
	{
		$lists = $this->dashboard->getInProgressTicketsPerUser();
		$count_total = count($this->dashboard->getInProgressTicketsPerUser());

		// Datatables Variables
        $draw = intval($this->input->get("draw"));
        $start = intval($this->input->get("start"));
        $length = intval($this->input->get("length"));

		$data = array();
		$count = 1;

		foreach($lists as $list)
		{
			$count++;
			$row = array();

			/* Measure elapsed mins of ticket */
			$datetime_added = strtotime($list['prod_date_updated']);
			$current_datetime= strtotime(date("Y-m-d H:i:s"));
			$interval = abs($datetime_added - $current_datetime);
			$elapsed_mins   = round($interval / 60);
			/* End */

			/** Date Created **/
			$prod_date_updated = "";
			$entry = $list['prod_date_updated'];
			if($entry == NULL)
			{
				$prod_date_updated	= "---";
			}
			else
			{
				$prod_date_updated = date("m/d/Y h:ia", strtotime($list['prod_date_updated']));
			}
			$row[] = $prod_date_updated;
			/** END **/


			/** Name **/
			if($this->position == 9)
			{
				$row[] = "<p>".userFullName($list['prod_added_by'])."</p>";
			/** End **/
			}


			/** Jira Ticket **/
			$row[] = "<a href='".$list['prod_ticket_Jira_Link']."' target='_blank'>".strtoupper($list['prod_ticket_ID'])."</a>";
			/** End **/


			/** Last Touched Status **/
			$row[] = "<p><strong>".$list['prod_jira_status']."</strong></p>";
			/** End **/

			/** Build Type **/
			$build_html = "";
			$disabledChange = "";
			$produc_type = get_produc_type($list['prod_tracker_ID'], $list['prod_produc_id']);
			$produc_id = get_produc_type($list['prod_tracker_ID'], $list['prod_produc_id']);
			$productives = list_productive_tasks();
			$build_types = list_build_types();

			if($list['build_type_selected'] == 1 && $this->position != 9)
			{
				$disabledChange = "disabled";
			}

			$build_html = '<select class="form-control changeBuildType" '.$disabledChange.' name="changeBuildType" data-id="'.encrypt($list['prod_tracker_ID']).'" data-has-started="'.$list['has_started'].'" data-elapsed-mins="'.$elapsed_mins.'" data-current-produc-id="'.encrypt($produc_id['produc_id']).'">';
			$build_html .= "<option value='0'>[-- Select Type of Build --]</option>";
			foreach($build_types as $build_type)
			{
				$selected = "";
				$disabled = "";
				if($list['prod_build_type'] == $build_type['type_id'])
				{
					$selected = "selected";
				}
				else
				{
					$selected = "";
				}

    			$build_html .= '<option value="'.$build_type['type_id'].'" '.$selected.' '.$disabled.' >'.$build_type['ticket_type_desc'].'</option>';
			}
			$build_html .= '</select>';
    		$row[] = $build_html;
			/** End **/


			/** Total Time Spent **/
			// $row[] = "<p><strong><a href='javascript:void(0)' class='view_total_spent_time' data-ticket-id='".$list['prod_ticket_ID']."'>Show Total</a></strong></p>";
			$row[] = "<p><strong><a href='javascript:void(0)' class='view_total_spent_time' data-ticket-id='".$list['prod_ticket_ID']."'><i class='fa fa-spinner fa-spin'></i> Processing</a></strong></p>";
			/** End **/


			/** Total Kickbacks **/
			// $row[] = "<p><strong><a href='javascript:void(0)' class='view_more_details' data-ticket-id='".$list['prod_ticket_ID']."'>View</a></strong></p>";
			$row[] = "<p><strong><a href='javascript:void(0)' class='view_more_details' data-ticket-id='".$list['prod_ticket_ID']."'><i class='fa fa-cog fa-spin'></i> Calculating</a></strong></p>";
			/** End **/


			/** Remarks **/
			$row[]  = '<strong><p contenteditable data-id="'.encrypt($list['prod_tracker_ID']).'" class="save_remarks">'.$list['remarks'].'</p></strong>';
			/** End **/

			$data[] = $row;
		}

		 $output = array(
            "draw" => $draw,
            "recordsTotal" => $count_total,
            "recordsFiltered" => $count_total,
            "data" => $data,
        );

	    echo json_encode($output); //output is in json format for ajax call
	}

	public function ajaxClosedTickets()
	{
		$lists = $this->dashboard->getClosedTicketsPerUser();
		$count_total = count($this->dashboard->getClosedTicketsPerUser());

		// Datatables Variables
        $draw = intval($this->input->get("draw"));
        $start = intval($this->input->get("start"));
        $length = intval($this->input->get("length"));

		$data = array();
		$count = 1;

		foreach($lists as $list)
		{
			$count++;
			$row = array();

			/* Measure elapsed mins of ticket */
			$datetime_added = strtotime($list['prod_date_updated']);
			$current_datetime= strtotime(date("Y-m-d H:i:s"));
			$interval = abs($datetime_added - $current_datetime);
			$elapsed_mins   = round($interval / 60);
			/* End */

			/** Date Created **/
			$prod_date_updated = "";
			$entry = $list['prod_date_updated'];
			if($entry == NULL)
			{
				$prod_date_updated	= "---";
			}
			else
			{
				$prod_date_updated = date("m/d/Y h:ia", strtotime($list['prod_date_updated']));
			}
			$row[] = $prod_date_updated;
			/** END **/


			/** Name **/
			if($this->position == 9)
			{
				$row[] = "<p>".userFullName($list['prod_added_by'])."</p>";
			}
			/** End **/


			/** Jira Ticket **/
			$row[] = "<a href='".$list['prod_ticket_Jira_Link']."' target='_blank'>".strtoupper($list['prod_ticket_ID'])."</a>";
			/** End **/


			/** Last Touched Status **/
    		$row[] = "<p><strong>".$list['prod_jira_status']."</strong></p>";
			/** End **/

			/** Build Type **/
			$build_html = "";
			$disabledChange = "";
			$produc_type = get_produc_type($list['prod_tracker_ID'], $list['prod_produc_id']);
			$produc_id = get_produc_type($list['prod_tracker_ID'], $list['prod_produc_id']);
			$productives = list_productive_tasks();
			$build_types = list_build_types();

			if($list['build_type_selected'] == 1 && $this->position != 9)
			{
				$disabledChange = "disabled";
			}

			$build_html = '<select class="form-control changeBuildType" '.$disabledChange.' name="changeBuildType" data-id="'.encrypt($list['prod_tracker_ID']).'" data-has-started="'.$list['has_started'].'" data-elapsed-mins="'.$elapsed_mins.'" data-current-produc-id="'.encrypt($produc_id['produc_id']).'">';
			$build_html .= "<option value='0'>[-- Select Type of Build --]</option>";
			foreach($build_types as $build_type)
			{
				$selected = "";
				$disabled = "";
				if($list['prod_build_type'] == $build_type['type_id'])
				{
					$selected = "selected";
				}
				else
				{
					$selected = "";
				}
    			$build_html .= '<option value="'.$build_type['type_id'].'" '.$selected.' '.$disabled.' >'.$build_type['ticket_type_desc'].'</option>';
			}
			$build_html .= '</select>';
    		$row[] = $build_html;
			/** End **/


			/** Total Time Spent **/
			$row[] = "<p><strong><a href='javascript:void(0)' class='view_total_spent_time' data-ticket-id='".$list['prod_ticket_ID']."'>Show Total</a></strong></p>";
			/** End **/


			/** Total Kickbacks **/
			$row[] = "<p><strong><a href='javascript:void(0)' class='view_more_details' data-ticket-id='".$list['prod_ticket_ID']."'>View</a></strong></p>";
			/** End **/


			/** Remarks **/
			$row[]  = '<strong><p contenteditable data-id="'.encrypt($list['prod_tracker_ID']).'" class="save_remarks">'.$list['remarks'].'</p></strong>';
			/** End **/

			$data[] = $row;
		}

		 $output = array(
            "draw" => $draw,
            "recordsTotal" => $count_total,
            "recordsFiltered" => $count_total,
            "data" => $data,
        );
	    echo json_encode($output); //output is in json format for ajax call
	}


	/** FETCH IN NEWLY UPDATED TICKETS **/
	public function fetch_updated_tickets()
	{
		/** ACCESS CURL TO THE JIRA API **/
		$email = 'sample@email.com';
		$pass  = base64_decode('samplePass43'); //decode the encoded jira password
		$user_email = $this->session->userdata('emp_email');
		$datetime_now_iso8601 = date("c");

		$service_url = 'https://companysupport.atlassian.net/rest/api/2/search?jql=assignee='.'"'.$user_email.'"%20AND%20Updated>=startOfDay()%20AND%20Updated<=endOfDay()%20AND%20status%20not%20in%20(%22Task%20Closed%22,%22Site%20is%20live%22,%22Revisions%20Complete%22)%20ORDER%20BY%20cf[12100]%20DESC&startAt=0&maxResults=1000'; //query newly updated tickets for the day

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($curl, CURLOPT_USERPWD, "$email:$pass");
		curl_setopt($curl, CURLOPT_URL, $service_url);
		curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($curl, CURLOPT_ENCODING, '');
		$curl_response = curl_exec($curl);
		$responses = json_decode($curl_response);
		/** END CURL **/

		/** Save entries to our database after deleting the prev data **/
		$entries = array();

		foreach($responses->issues as $resp)
		{
			$date_created = new DateTime($resp->fields->created);
			$date_updated = new DateTime($resp->fields->updated);
			$date_created->setTimezone(new DateTimeZone('Asia/Singapore'));
			$date_updated->setTimezone(new DateTimeZone('Asia/Singapore'));

			$status;

			if($resp->fields->status->name != "Task Closed" || $resp->fields->status->name != "Site is live")
			{
				$status = 0;
			}
			if($resp->fields->status->name == "Task Closed" || $resp->fields->status->name == "Site is live")
			{
				$status = 5; //ticket is already closed
			}

			$ticket_id = $resp->key; //jira ticket id
			$retain_ticket_status = $this->dashboard->get_ticket_status($ticket_id);

			$insert_data = array(
				'prod_added_by'					=> $this->session->userdata('emp_id'),
				'prod_ticket_ID' 				=> $ticket_id,
				'prod_ticket_Jira_Link' 		=> "https://companysupport.atlassian.net/browse/".$resp->key,
				'prod_dmc_email'  				=> $resp->fields->reporter->emailAddress,
				'prod_builder_email' 			=> $resp->fields->assignee->emailAddress,
				'prod_biz_name'					=> $resp->fields->summary,
				'prod_priority' 				=> $resp->fields->priority->name,
				'prod_salesforce' 				=> $resp->fields->customfield_12001,
				'prod_appointment' 				=> $resp->fields->customfield_12100,
				'prod_jira_status' 				=> $resp->fields->status->name,
				'prod_date_added'				=> $date_created->format('Y-m-d H:i:s'),
				'prod_date_updated'				=> $date_updated->format('Y-m-d H:i:s')
			);
			$inserted = $this->dashboard->insert_or_update('prod_tracker', $ticket_id, $insert_data);
		}
		curl_close($curl);

		redirect(base_url("dashboard"), "refresh");
	}

	/** FETCH CLOSED TICKETS WHEN BUTTON WAS CLICKED **/
	public function fetch_finished_tickets()
	{
		/** ACCESS CURL TO THE JIRA API **/
		$email = 'sample@email.com';
		$pass  = base64_decode('samplePass43'); //decode the encoded jira password
		$user_email = $this->session->userdata('emp_email');
		$datetime_now_iso8601 = date("c");

		$closed_records = $this->dashboard->getClosedTicketsPerUser($this->emp_id);
		$service_url = '';


		if(!empty($closed_records))
		{
			$service_url = 'https://companysupport.atlassian.net/rest/api/2/search?jql=assignee='.'"'.$user_email.'"%20AND%20Updated>=startOfDay()%20AND%20Updated<=endOfDay()%20AND%20status%20%20in%20(%22Task%20Closed%22,%22Site%20is%20live%22,%22Revisions%20Complete%22)%20ORDER%20BY%20cf[12100]%20DESC&startAt=0&maxResults=1000';
			//get tickets created within the day if db entries are not empty (this does not inclde the task closed, site is live and revisions complete tickets)
		}
		else
		{
			$service_url = 'https://companysupport.atlassian.net/rest/api/2/search?jql=assignee='.'"'.$user_email.'"%20AND%20status%20in%20(%22Task%20Closed%22,%22Site%20is%20live%22,%22Revisions%20Complete%22)%20ORDER%20BY%20cf[12100]%20DESC&startAt=0&maxResults=1000';
			//get all tickets if not record yet
		}

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($curl, CURLOPT_USERPWD, "$email:$pass");
		curl_setopt($curl, CURLOPT_URL, $service_url);
		curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($curl, CURLOPT_ENCODING, '');
		$curl_response = curl_exec($curl);
		$responses = json_decode($curl_response);
		/** END CURL **/

		/** Save entries to our database after deleting the prev data **/
		$entries = array();

		foreach($responses->issues as $resp)
		{
			$date_created = new DateTime($resp->fields->created);
			$date_updated = new DateTime($resp->fields->updated);
			$date_created->setTimezone(new DateTimeZone('Asia/Singapore'));
			$date_updated->setTimezone(new DateTimeZone('Asia/Singapore'));
			$status;

			if($resp->fields->status->name != "Task Closed" || $resp->fields->status->name != "Site is live")
			{
				$status = 0;
			}
			if($resp->fields->status->name == "Task Closed" || $resp->fields->status->name == "Site is live")
			{
				$status = 5; //ticket is already closed
			}

			$ticket_id = $resp->key; //jira ticket id
			$retain_ticket_status = $this->dashboard->get_ticket_status($ticket_id);

			$insert_data = array(
				'prod_added_by'					=> $this->session->userdata('emp_id'),
				'prod_ticket_ID' 				=> $ticket_id,
				'prod_ticket_Jira_Link' 		=> "https://companysupport.atlassian.net/browse/".$resp->key,
				'prod_dmc_email'  				=> $resp->fields->reporter->emailAddress,
				'prod_builder_email' 			=> $resp->fields->assignee->emailAddress,
				'prod_biz_name'					=> $resp->fields->summary,
				'prod_priority' 				=> $resp->fields->priority->name,
				'prod_salesforce' 				=> $resp->fields->customfield_12001,
				'prod_appointment' 				=> $resp->fields->customfield_12100,
				'prod_jira_status' 				=> $resp->fields->status->name,
				'prod_date_added'				=> $date_created->format('Y-m-d H:i:s'),
				'prod_date_updated'				=> $date_updated->format('Y-m-d H:i:s')
			);
			$inserted = $this->dashboard->insert_or_update('prod_tracker', $ticket_id, $insert_data);
		}

		curl_close($curl);
		redirect(base_url("dashboard"), "refresh");
	}


	/** Change task status **/
	public function change_status()
	{
		$data_post = $this->input->post();

		$id = decrypt($data_post['tracker_id']);
		$status = $data_post['status'];
		$has_started = $data_post['has_started'];
		$elapsed_mins = $data_post['elapsed_mins'];
		$curr_produc_id = decrypt($data_post['current_produc_id']);

		if($has_started == 0)
		{
			$array = array(
				"prod_status" 		=> $status,
				"prod_date_started" => date("Y-m-d H:i:s"),
				"has_started"		=> 1
			);
		}

		if($has_started == 1)
		{
			$array = array(
				"prod_status" 		=> $status,
				"has_started"		=> 1
			);
		}

		/** If ticket was closed, save duration to db **/
		if($status == 5)
		{
			$date_added = strtotime(get_ticket_date_started($id)['prod_date_added']);
			$current_datetime= strtotime(date("Y-m-d H:i:s"));
			$interval = abs($date_added - $current_datetime);
			$elapsed_mins   = round($interval / 60);

			$duration = array(
				'prod_ticket_life' 		=> $elapsed_mins,
				'prod_date_finished'	=> date('Y-m-d H:i:s')
			);
			$this->dashboard->update_duration($id, $duration);

			/** SAVE NOTIFICATION **/
			$notif_array = array(
				'prod_tracker_id' => $id,
				'emp_id'		  => $this->emp_id,
				'date_notified'	  => date('Y-m-d H:i:s')
			);
			$this->dashboard->insert_notification($notif_array);
		}
		$this->dashboard->change_ticket_status($id, $array);


		/** Process productive tasks from other table **/
		$started_at_val = get_produc_type($id, $curr_produc_id);
		$started_at = strtotime($started_at_val['produc_started_at']);
		$current_datetime= strtotime(date("Y-m-d H:i:s"));
		$interval = abs($started_at - $current_datetime);
		$elapsed_mins   = round($interval / 60);

		$ended_at = array(
			"produc_ended_at" 			=> date("Y-m-d H:i:s"),
			"produc_total_duration"		=> $elapsed_mins
		);
		$updated_ended_at = $this->dashboard->update_produc_ended_at($curr_produc_id, $ended_at);

		if($updated_ended_at)
		{
			$array = array(
				"prod_tracker_id" 		=> $id,
				"produc_started_at"		=> date("Y-m-d H:i:s"),
				"produc_type"  			=> $status,
			);

			$updated = $this->dashboard->change_ticket_produc_status($id, $array);

			if($updated > 0)
			{
				$data = array(
					'prod_produc_id' => $updated
				);
				$this->dashboard->update_prod_tracker_productive($id, $data);
			}
		}
		/** END **/

		//** For the response (include_bottom.php)
		$this->response_code 		= 0;
		$this->response_message		= "Update Successful.";

		echo json_encode(array(
			"error"		=> $this->response_code,
			"message"	=> $this->response_message
		));
	}


	public function change_build_type()
	{
		$data_post = $this->input->post();

		$id = decrypt($data_post['tracker_id']);
		$status = $data_post['status'];

		/** Process productive tasks from other table **/
		if($data_post)
		{
			$array = array(
				"prod_build_type"  		=> $status,
				"build_type_selected"	=> 1,
				"prod_date_build_type_updated" => date("Y-m-d H:i:s"),
			);

			$updated = $this->dashboard->update_build_type($id, $array);

			if($updated)
			{
				$this->response_code 		= 0;
				$this->response_message		= "Build Type Update Successful.";
			}
			else
			{
				$this->response_message		= "Build Type Update Failed.";
			}
		}
		/** END **/

		echo json_encode(array(
			"error"		=> $this->response_code,
			"message"	=> $this->response_message
		));
	}

	/** Non Productive Tasks Update **/
	public function update_nonproductive_status()
	{
		$data_post = $this->input->post();

		$id = decrypt($data_post["id"]);
		$email = decrypt($data_post["email"]);
		$current_type = $data_post["current_nonproductive_type"];
		$new_type = $data_post["new_nonproductive_type"];

		/** Before inserting new data to nonproductive table, update the previous end_at column **/
		$started_at_val = get_nonproduc_type($email);
		$started_at = strtotime($started_at_val['started_at']);
		$current_datetime= strtotime(date("Y-m-d H:i:s"));
		$interval = abs($started_at - $current_datetime);
		$elapsed_mins   = round($interval / 60);

		$ended_at = array(
			"ended_at" 			=> date("Y-m-d H:i:s"),
			"total_duration"	=> $elapsed_mins
		);

		$updated_ended_at = $this->dashboard->update_nonproduc_ended_at($id, $email, $current_type, $ended_at);

		if($updated_ended_at)
		{
			$array = array(
				"emp_id"			=> $this->emp_id,
				"emp_email" 		=> $email,
				"started_at"		=> date("Y-m-d H:i:s"),
				"nonproduc_type"  	=> $new_type,
			);
			$this->dashboard->change_ticket_nonproductive_status($array);

			$this->response_code 		= 0;
			$this->response_message		= "Activity Updated Successfully.";
		}
		else
		{
			$this->response_message		= "Activity Failed To Update. Please try again.";
		}

		echo json_encode(array(
			"error"		=> $this->response_code,
			"message"	=> $this->response_message
		));
	}


	/** REMARKS FEATURE **/
	public function save_remarks()
	{
		$data_post = $this->input->post();
		$id = decrypt($data_post['tracker_id']);
		$content = $data_post['remarks'];

		$remark_data = array(
			'remarks'  => $content,
		);

		$updated = $this->dashboard->update_remarks($id, $remark_data);

		if($updated)
		{
			$this->response_code = 0;
			$this->response_message = "Remarks Saved.";
		}
		else
		{
			$this->response_message = "Failed to Save remarks.";
		}

		echo json_encode(array(
			"error"		=> $this->response_code,
			"message"	=> $this->response_message
		));
	}
	/** END **/

	public function special_projects()
	{
		$data['title'] = "Special Projects";

		$data['members'] = $this->team->members($this->position);
		$this->load->view("Dashboard/special_project", $data);
	}

	public function save_special_project()
	{
		$post_data = $this->input->post();

		$this->form_validation->set_rules('task_name', 'Project/Task Name', 'required');
		$this->form_validation->set_rules('duration',  'Duration', 'required|integer');
		$this->form_validation->set_rules('assignee',  'Assignee', 'required');

		if($post_data)
		{
			if($this->form_validation->run())
			{
				$prod_tracker_data = array(
					'prod_added_by'				=> decrypt($post_data['assignee']),
					'prod_ticket_Jira_Link' 	=> $post_data['jira_link'],
					'prod_date_added'			=> date('Y-m-d H:i:s'),
					'prod_date_updated'			=> date('Y-m-d H:i:s'),
					'is_special_project'		=> 1,
					'remarks'					=> $post_data['remarks']
				);

				$prod_tracker_inserted = $this->dashboard->insert_special_project("prod_tracker", $prod_tracker_data);

				if($prod_tracker_inserted > 0)
				{
					$special_project_data = array(
						'prod_tracker_id'	=> $prod_tracker_inserted,
						'created_by'		=> $post_data['admin_id'],
						'assigned_to'		=> decrypt($post_data['assignee']),
						'duration'			=> $post_data['duration'],
						'remarks'			=> $post_data['remarks'],
						'created_on'		=> date('Y-m-d H:i:s')
					);
					$special_project_inserted = $this->dashboard->insert_special_project("prod_tracker_special_projects", $special_project_data);

					if($special_project_inserted > 0)
					{
						$this->response_code = 0;
						$this->response_message = "Successfully assigned special project.";
					}
					else
					{
						$this->response_message = "Failed to assign special project.";
					}
				}
			}
			else
			{
				$this->response_message = validation_errors("<span></span>");
			}
		}

		echo json_encode(array(
			"error"		=> $this->response_code,
			"message"	=> $this->response_message
		));
	}

}
