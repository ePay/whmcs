<?php
include("../../../dbconnect.php");
include("../../../includes/functions.php");
include("../../../includes/gatewayfunctions.php");
include("../../../includes/invoicefunctions.php");

$gateway = getGatewayVariables("epay");

if (!$gateway["type"])
	die("Module Not Activated");

//Get callback parameters
$invoiceid = $_GET["orderid"];
$transid = $_GET["txnid"];
$amount = $_GET["amount"] / 100;
$fee = $_GET["txnfee"] / 100;

//Check invoice
$invoiceid = checkCbInvoiceID($invoiceid, $gateway["name"]);
checkCbTransID($transid);

//Calculate hash
$params = $_GET;
$var = "";

foreach ($params as $key => $value)
{
	if($key != "hash")
	{
		$var .= $value;
	}
}

$genstamp = md5($var . $gateway["md5key"]);

//Check hash
if($genstamp != $_GET["hash"])
{
	logTransaction($gateway["name"], $_GET, "Unsuccessful");
}
else
{
	//Hash OK
	
	//Add fee to invoice
	if($fee > 0)
	{
		$fee_values["invoiceid"] = $invoiceid;
		$fee_values["newitemdescription"] = array("Payment Fee");
		$fee_values["newitemamount"] = array($fee);
		$fee_values["newitemtaxed"] = array("0");
		$fee_result = localAPI("updateinvoice", $fee_values, null);
	}
	
	//Add payment to invoice
	addInvoicePayment($invoiceid, $transid, $amount + $fee, $fee, "epay");

	logTransaction($gateway["name"], $_GET, "Successful");
	
	//Save subscription information
	if($_GET["subscriptionid"])
	{
		//Get expire date
		$soap = new SoapClient("https://ssl.ditonlinebetalingssystem.dk/remote/subscription.asmx?wsdl");
		$soap_subscription_result = $soap->getsubscriptions(array("merchantnumber" => $gateway["merchantnumber"], "subscriptionid" => $_GET["subscriptionid"], "epayresponse" => -1));

		if($soap_subscription_result->getsubscriptionsResult == true)
		{
			$expmonth = $soap_subscription_result->subscriptionAry->SubscriptionInformationType->expmonth;
			$expyear = $soap_subscription_result->subscriptionAry->SubscriptionInformationType->expyear;
			
			full_query("UPDATE tblclients set cardtype = 'Payment card', cardnum = AES_ENCRYPT('" . $_GET['cardno'] . "', MD5('". $cc_encryption_hash . $_GET["clientid"] . "')), expdate = AES_ENCRYPT('" . $expmonth . $expyear . "', MD5('". $cc_encryption_hash . $_GET["clientid"] . "')) WHERE id = ". $_GET["clientid"]);
		}
		
		//Save subscriptionid
		update_query("tblclients", array("gatewayid" => $_GET["subscriptionid"]), array("id" => $_GET["clientid"]));
	}
}
?>