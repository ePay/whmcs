<?php
if (!defined("WHMCS"))
	die("This file cannot be accessed directly");
	
require("../../init.php");
$whmcs->load_function('gateway');
$whmcs->load_function('invoice');
$whmcs->load_function('client');
$whmcs->load_function('cc');

function epay_invoice_creation_pre_email($vars)
{
	global $cc_encryption_hash;
	
	logActivity("epay_invoice_creation_pre_email: " . $vars['invoiceid']);
	
	//Fetch invoice data
	$values["invoiceid"] = $vars['invoiceid'];
	$invoice_result = localAPI("getinvoice", $values, null);
	
	//Should this invoice be payed with ePay
	if($invoice_result['paymentmethod'] == "epay")
	{
		$gateway = getGatewayVariables("epay");
		
		//Get client
		$client_query = mysql_query("SELECT id, currency, gatewayid, disableautocc, AES_DECRYPT(cardnum, MD5('" . $cc_encryption_hash . $invoice_result['userid'] . "')) as cardnum FROM tblclients WHERE id = " . $invoice_result['userid']);
  		$client_result = mysql_fetch_array($client_query);
		
		//Set subscription ID
		$subscriptionid = $client_result['gatewayid'];
		
		//Get currency
		$currency_query = select_query("tblcurrencies", "id, code", array("id" => $client_result['currency']));
		$currency_result = mysql_fetch_array($currency_query);
		
		$currency_code = convertToEpayCurrency($currency_result["code"]);
		logActivity("epay_invoice_creation_pre_email: " . $currency_code);
		
		//Process if autocc is not disabled
		if($client_result['disableautocc'] != "on" && $subscriptionid > 0)
		{
			$fee = 0;
			
			logActivity("epay_invoice_creation_pre_email: " . $invoice_result['total'] * 100);
			logActivity("epay_invoice_creation_pre_email: " . $gateway["subscriptionfee"] );
			
			//Calculate transaction fee
			if($gateway["subscriptionfee"] == "on")
			{
				logActivity("epay_invoice_creation_pre_email card: " . $client_result["cardnum"]);
				
				$epay_params = array();
				$epay_params['merchantnumber'] = $gateway["merchantnumber"];
				$epay_params['cardno_prefix'] = substr($client_result["cardnum"], 0, 6);
				$epay_params['amount'] = $invoice_result['total'] * 100;
				$epay_params['currency'] = $currency_code;
				$epay_params['acquirer'] = "0";
				$epay_params['fee'] = "0";
				$epay_params['cardtype'] = "ALL";
				$epay_params['cardtypetext'] = "-1";
				$epay_params['epayresponse'] = "-1";

				$soap = new SoapClient('https://ssl.ditonlinebetalingssystem.dk/remote/payment.asmx?WSDL');
				$soap_fee_result = $soap->getcardinfo($epay_params);
				$fee = $soap_fee_result->fee;
				
				logActivity("epay_invoice_creation_pre_email fee: " . $fee);
			}
			
			//Autorize transaction
			$epay_params = array();
			$epay_params['merchantnumber'] = $gateway["merchantnumber"];
			$epay_params['subscriptionid'] = $subscriptionid;
			$epay_params['orderid'] = $vars['invoiceid'];
			$epay_params['amount'] = ($invoice_result['total'] * 100) + $fee;
			$epay_params['currency'] = $currency_code;
			
			if($gateway["captureonduedate"] == "no" OR array_key_exists("SERVER_ADDR", $_SERVER))
				$epay_params['instantcapture'] = "1";
			else
				$epay_params['instantcapture'] = "0";
				
			$epay_params['fraud'] = "0";
			$epay_params['transactionid'] = "-1";
			$epay_params['pbsresponse'] = "-1";
			$epay_params['epayresponse'] = "-1";

			$soap = new SoapClient('https://ssl.ditonlinebetalingssystem.dk/remote/subscription.asmx?WSDL');
			$soap_authorize_result = $soap->authorize($epay_params);

			//Transaction OK
			if($soap_authorize_result->authorizeResult == true)
			{
				//Apply fee to invoice
				
				if($fee > 0)
				{
					$values_fee["invoiceid"] = $vars['invoiceid'];
					$values_fee["newitemdescription"] = array("Payment Fee");
					$values_fee["newitemamount"] = array($fee / 100);
					$values_fee["newitemtaxed"] = array("0");
					$result_fee = localAPI("updateinvoice", $values_fee, null);
					
					logActivity("epay_invoice_creation_pre_email fee result: " . $result_fee["result"]);
				}
				
				//Add payment to invoice
				$transactionid = $soap_authorize_result->transactionid;
				if($gateway["captureonduedate"] == "no" OR array_key_exists("SERVER_ADDR", $_SERVER))
					addInvoicePayment($vars['invoiceid'], $transactionid, $invoice_result['total'] + ($fee / 100), ($fee / 100), "epay");
				else
				{
					//Add to capture queue
					mysql_query("INSERT INTO tblepay (invoiceid, txnid) VALUES ('" . $vars['invoiceid'] . "', " . $transactionid . ")");
				}
			}
			else
			{
				//Could not authorize transaction
				//Log and let WHMCS handle further processing
				logActivity("epay_invoice_creation_pre_email: PBS: " . $soap_authorize_result->pbsresponse . " ePay: " . $soap_authorize_result->epayresponse);
			}
		}
	}
}

function epay_invoice_payment_reminder($vars)
{
	$new_vars = array();
	$new_vars["invoiceid"] = $vars["invoiceid"];
	epay_invoice_creation_pre_email($new_vars);
}

function epay_daily_cron_job()
{
	$gateway = getGatewayVariables("epay");
	
	$query = mysql_query("SELECT * FROM tblinvoices INNER JOIN tblepay epay ON epay.invoiceid = tblinvoices.id WHERE tblinvoices.paymentmethod = 'epay' AND tblinvoices.status = 'Unpaid' AND (tblinvoices.duedate <= NOW() + INTERVAL 1 DAY) AND epay.`status` = 0");
	
	while($row = mysql_fetch_array($query))
	{	
		try
		{
			//Capture
			$epay_params['merchantnumber'] = $gateway["merchantnumber"];
			$epay_params['amount'] = ($row['total'] * 100);
			$epay_params['transactionid'] = $row['txnid'];
			$epay_params['group'] = "";
			$epay_params['pbsResponse'] = "-1";
			$epay_params['epayresponse'] = "-1";
			
			$soap = new SoapClient('https://ssl.ditonlinebetalingssystem.dk/remote/payment.asmx?WSDL');
			$soap_capture_result = $soap->capture($epay_params);
			
			//Transaction OK
			if($soap_capture_result->captureResult == true)
			{
				//OK capture
				addInvoicePayment($row['invoiceid'], $row['txnid'], $row['total'], 0, "epay");
				mysql_query("UPDATE tblepay SET status = 1 WHERE invoiceid = " . $row['invoiceid']);
			}
			else
			{
				//Error in capture
				logActivity("epay_daily_cron_job: TXNID: " . $row['txnid'] . " PBS: " . $soap_capture_result->pbsresponse . " ePay: " . $soap_capture_result->epayresponse);
				mysql_query("UPDATE tblepay SET status = 2 WHERE invoiceid = " . $row['invoiceid']);
			}
		}
		catch(Exception $e)
		{
			logActivity("epay_daily_cron_job: " . $e->getMessage());
		}
	}
}

//Register hooks
add_hook("InvoiceCreationPreEmail", 1, "epay_invoice_creation_pre_email");
add_hook("InvoicePaymentReminder", 1, "epay_invoice_payment_reminder");
add_hook("DailyCronJob", 1, "epay_daily_cron_job");

//Convert currency to number
function convertToEpayCurrency($cur)
{
	switch(strtoupper($cur))
	{
		case "ADP":
			return "020";
		case "AED":
			return "784";
		case "AFA":
			return "004";
		case "ALL":
			return "008";
		case "AMD":
			return "051";
		case "ANG":
			return "532";
		case "AOA":
			return "973";
		case "ARS":
			return "032";
		case "AUD":
			return "036";
		case "AWG":
			return "533";
		case "AZM":
			return "031";
		case "BAM":
			return "977";
		case "BBD":
			return "052";
		case "BDT":
			return "050";
		case "BGL":
			return "100";
		case "BGN":
			return "975";
		case "BHD":
			return "048";
		case "BIF":
			return "108";
		case "BMD":
			return "060";
		case "BND":
			return "096";
		case "BOB":
			return "068";
		case "BOV":
			return "984";
		case "BRL":
			return "986";
		case "BSD":
			return "044";
		case "BTN":
			return "064";
		case "BWP":
			return "072";
		case "BYR":
			return "974";
		case "BZD":
			return "084";
		case "CAD":
			return "124";
		case "CDF":
			return "976";
		case "CHF":
			return "756";
		case "CLF":
			return "990";
		case "CLP":
			return "152";
		case "CNY":
			return "156";
		case "COP":
			return "170";
		case "CRC":
			return "188";
		case "CUP":
			return "192";
		case "CVE":
			return "132";
		case "CYP":
			return "196";
		case "CZK":
			return "203";
		case "DJF":
			return "262";
		case "DKK":
			return "208";
		case "DOP":
			return "214";
		case "DZD":
			return "012";
		case "ECS":
			return "218";
		case "ECV":
			return "983";
		case "EEK":
			return "233";
		case "EGP":
			return "818";
		case "ERN":
			return "232";
		case "ETB":
			return "230";
		case "EUR":
			return "978";
		case "FJD":
			return "242";
		case "FKP":
			return "238";
		case "GBP":
			return "826";
		case "GEL":
			return "981";
		case "GHC":
			return "288";
		case "GIP":
			return "292";
		case "GMD":
			return "270";
		case "GNF":
			return "324";
		case "GTQ":
			return "320";
		case "GWP":
			return "624";
		case "GYD":
			return "328";
		case "HKD":
			return "344";
		case "HNL":
			return "340";
		case "HRK":
			return "191";
		case "HTG":
			return "332";
		case "HUF":
			return "348";
		case "IDR":
			return "360";
		case "ILS":
			return "376";
		case "INR":
			return "356";
		case "IQD":
			return "368";
		case "IRR":
			return "364";
		case "ISK":
			return "352";
		case "JMD":
			return "388";
		case "JOD":
			return "400";
		case "JPY":
			return "392";
		case "KES":
			return "404";
		case "KGS":
			return "417";
		case "KHR":
			return "116";
		case "KMF":
			return "174";
		case "KPW":
			return "408";
		case "KRW":
			return "410";
		case "KWD":
			return "414";
		case "KYD":
			return "136";
		case "KZT":
			return "398";
		case "LAK":
			return "418";
		case "LBP":
			return "422";
		case "LKR":
			return "144";
		case "LRD":
			return "430";
		case "LSL":
			return "426";
		case "LTL":
			return "440";
		case "LVL":
			return "428";
		case "LYD":
			return "434";
		case "MAD":
			return "504";
		case "MDL":
			return "498";
		case "MGF":
			return "450";
		case "MKD":
			return "807";
		case "MMK":
			return "104";
		case "MNT":
			return "496";
		case "MOP":
			return "446";
		case "MRO":
			return "478";
		case "MTL":
			return "470";
		case "MUR":
			return "480";
		case "MVR":
			return "462";
		case "MWK":
			return "454";
		case "MXN":
			return "484";
		case "MXV":
			return "979";
		case "MYR":
			return "458";
		case "MZM":
			return "508";
		case "NAD":
			return "516";
		case "NGN":
			return "566";
		case "NIO":
			return "558";
		case "NOK":
			return "578";
		case "NPR":
			return "524";
		case "NZD":
			return "554";
		case "OMR":
			return "512";
		case "PAB":
			return "590";
		case "PEN":
			return "604";
		case "PGK":
			return "598";
		case "PHP":
			return "608";
		case "PKR":
			return "586";
		case "PLN":
			return "985";
		case "PYG":
			return "600";
		case "QAR":
			return "634";
		case "ROL":
			return "642";
		case "RUB":
			return "643";
		case "RUR":
			return "810";
		case "RWF":
			return "646";
		case "RSD":
			return "941";
		case "SAR":
			return "682";
		case "SBD":
			return "090";
		case "SCR":
			return "690";
		case "SDD":
			return "736";
		case "SEK":
			return "752";
		case "SGD":
			return "702";
		case "SHP":
			return "654";
		case "SIT":
			return "705";
		case "SKK":
			return "703";
		case "SLL":
			return "694";
		case "SOS":
			return "706";
		case "SRG":
			return "740";
		case "STD":
			return "678";
		case "SVC":
			return "222";
		case "SYP":
			return "760";
		case "SZL":
			return "748";
		case "THB":
			return "764";
		case "TJS":
			return "972";
		case "TMM":
			return "795";
		case "TND":
			return "788";
		case "TOP":
			return "776";
		case "TPE":
			return "626";
		case "TRL":
			return "792";
		case "TRY":
			return "949";
		case "TTD":
			return "780";
		case "TWD":
			return "901";
		case "TZS":
			return "834";
		case "UAH":
			return "980";
		case "UGX":
			return "800";
		case "USD":
			return "840";
		case "UYU":
			return "858";
		case "UZS":
			return "860";
		case "VEB":
			return "862";
		case "VND":
			return "704";
		case "VUV":
			return "548";
		case "XAF":
			return "950";
		case "XCD":
			return "951";
		case "XOF":
			return "952";
		case "XPF":
			return "953";
		case "YER":
			return "886";
		case "YUM":
			return "891";
		case "ZAR":
			return "710";
		case "ZMK":
			return "894";
		case "ZWD":
			return "716";
	}
	
	return "ERROR - CURRENCY COULD NOT BE TRANSLATED TO EPAY: " . $cur;
}