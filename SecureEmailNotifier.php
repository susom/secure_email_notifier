<?php
namespace Stanford\SecureEmailNotifier;

require_once "emLoggerTrait.php";
class SecureEmailNotifier extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    public $start_date;  // The date where we start from in looking for outbound SECURE messages
    public $now_date;    // The time being run

    public function queryEmails($start_dt, $end_dt) {
        $sql = "
            select
                roesl.project_id,
                rp.app_title,
                dcs.contact_email,
                dcs.contact_first_name,
                count(distinct roesl.recipients) as distinct_recipients,
                count(*) as total_emails
            from
                redcap_outgoing_email_sms_log roesl
            join designated_contact_selected dcs on dcs.project_id = roesl.project_id
            join redcap_projects rp on rp.project_id = roesl.project_id
            where
                type='EMAIL'
            and roesl.email_subject like '%SECURE:%'
            and roesl.recipients not like '%stanford.edu%'
            and roesl.time_sent > ?
            and roesl.time_sent <= ?
            group by roesl.project_id, rp.app_title, dcs.contact_email, dcs.contact_first_name";
        $q = $this->query($sql, [$start_dt, $end_dt]);
        $this->emDebug("Query returned", $q);

        // See if there are any PIDs to exclude
        $excluded_pids = $this->getSystemSetting('excluded-pids');
        // Convert everything that isn't a number to a comma
        $excluded_pids = preg_replace('/[^\d+]/', ',', $excluded_pids);
        $excluded_pids = array_filter(str_getcsv($excluded_pids));
        $this->emDebug("Excluded PIDs: " . json_encode($excluded_pids));

        // Group results by email address
        $emails = [];
        while ($row = $q->fetch_assoc()) {
            $project_id = $row['project_id'];

            if (in_array($project_id, $excluded_pids)) {
                $this->emDebug("Skipping project $project_id");
                continue;
            }

            $email = $row['contact_email'];
            // Initialize emails array with key if not present
            if (!isset($emails[$email])) $emails[$email] = [];
            $emails[$email][] = $row;
        }
        $this->emDebug("Grouped by Email payload", $emails);
        return $emails;
    }

    public function scanEmails() {
        // Get all grouped emails
        $payload = $this->queryEmails($this->start_date, $this->now_date);

        $email_body = $this->getSystemSetting('email-body');
        $email_from = $this->getSystemSetting('email-from');
        $email_subject = $this->getSystemSetting('email-subject');
        $email_cc = $this->getSystemSetting('email-cc');

        $outbound_emails = [];

        // Build an array of emails
        foreach ($payload as $email_addr => $rows) {
            $msg = [];

            // Build a table projects for each email
            $table_rows = [];
            $first_row = true;
            foreach ($rows as $row) {
                if ($first_row) {
                    $msg['to'] = $row['contact_email'];
                    $msg['from'] = $email_from;
                    $msg['subject'] = $email_subject;
                    $msg['cc'] = $email_cc;
                    $first_name = $row['contact_first_name'];
                    if (empty($first_name)) $first_name = "REDCap User";
                    $first_row = false;
                }
                $table_rows[] = "<tr>" .
                    "<td>" . $row['project_id'] . "</td>" .
                    "<td>" . $row['app_title']  . "</td>" .
                    "<td>" . $row['distinct_recipients']  . "</td>" .
                    "<td>" . $row['total_emails']  . "</td>" .
                    "</tr>";
            }

            $msg['body'] = "Dear $first_name,\n\n" .
                $email_body .
                "\n" .
                "<table style='border-spacing: 8px;'>" .
                "<thead><tr><th>PID</th><th>Project<br/>Title</th><th>Distinct<br/>Recipients</th><th>Email<br/>Count</th></tr></thead>" .
                "<tbody>" . implode("",$table_rows) . "</tbody>" .
                "</table>" .
                "\n\n<i>(This is an automated message based on the date range from " . $this->start_date . " to " . $this->now_date . ".  " .
                "Please contact your REDCap team if you wish to be exempted from future Secure Email Notification warnings on this project and specify your project_id/s)</i>";

            $outbound_emails[] = $msg;
        }

        $this->emDebug("Outbound Emails", $outbound_emails);

        //TODO: Send Actual Messages
        foreach ($outbound_emails as $email) {

            echo "<h4>Email</h4><pre style='text-wrap:wrap;'>" . print_r($email,true) . "</pre><hr/>";

            $this->emDebug("Emailing " . $email['to'], $email['subject'], $email['body'], $email['cc']);
            \REDCap::email($email['to'], $email['from'], $email['subject'], $email['body'], $email['cc']);
        }
    }

    public function checkToRun() {
        $this->now_date = date('Y-m-d H:i:s');

        $last_run = $this->getSystemSetting('last-run');
        if(empty($last_run)) {
            // We don't have a start-date, so let's set it to the first day of the month:
            $this->start_date = left($this->now_date, 8) . "01 00:00:00";
        } else {
            $this->start_date = $last_run;
        }

        $delta = strtotime($this->now_date) - strtotime($this->start_date);
        $this->emDebug("Start date is $this->start_date and it has been $delta seconds since last check");

        $scan_interval = intval($this->getSystemSetting('scan-interval'));
        if ($scan_interval == 0) $scan_interval = 259200;

        if ($delta > $scan_interval) {
            $this->emDebug("It has been more than $scan_interval since last scan - let's do this.");
            $this->scanEmails();

            // Update the date last run
            $this->setSystemSetting('last-run', $this->now_date);
        }

        // Update the date last checked
        $this->setSystemSetting('last-checked', $this->now_date);
    }


    public function cronScan( $cronParameters ) {
        $this->emDebug("cronScan called!", $cronParameters);
        $this->checkToRun();
    }
}
