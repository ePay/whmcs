<?php
function epay_config()
{
	$configarray = array
	(
		"FriendlyName" => array("Type" => "System", "Value" => "ePay"),
		"merchantnumber" => array("FriendlyName" => "Merchant Number", "Type" => "text", "Size" => "20"),
		"group" => array("FriendlyName" => "Group", "Type" => "text", "Size" => "20"),
		"md5key" => array("FriendlyName" => "MD5 Key", "Type" => "text", "Size" => "20"),
		"authsms" => array("FriendlyName" => "Auth SMS", "Type" => "text", "Size" => "20"),
		"authmail" => array("FriendlyName" => "Auth Mail", "Type" => "text", "Size" => "20"),
		"subscriptionfee" => array("FriendlyName" => "Add fee to subscription transactions", "Type" => "yesno")
	);
	return $configarray;
}

function epay_link($params)
{
	$merchantnumber = $params['merchantnumber'];
	$invoiceid = $params['invoiceid'];
	$amount = $params['amount'] * 100;
	
	//Parameters is descriped here: http://tech.epay.dk/en/specification
	$epay_params = array();
	$epay_params["merchantnumber"] = $merchantnumber;
	$epay_params["orderid"] = $invoiceid;
	$epay_params["currency"] = $params['currency'];
	$epay_params["amount"] = $amount;
	$epay_params["accepturl"] = $params['returnurl'];
	$epay_params["cancelurl"] = $params['systemurl'];
	$epay_params["windowstate"] = "3";
	$epay_params["callbackurl"] = $params['systemurl'] . '/modules/gateways/callback/epay.php?clientid=' . $params["clientdetails"]["id"];
	$epay_params["subscription"] = "1";
	$epay_params["subscriptionname"] = $params["clientdetails"]["id"];
	$epay_params["instantcallback"] = "1";
	$epay_params["instantcapture"] = "1";
	$epay_params["smsreceipt"] = $params["authsms"];
	$epay_params["mailreceipt"] = $params["authmail"];
	$epay_params["group"] = $params["group"];
	$epay_params["hash"] = md5(implode("", array_values($params)) . $params['md5key']);
	
	$code = '
	<form action="https://ssl.ditonlinebetalingssystem.dk/integration/ewindow/Default.aspx" method="post">';
		foreach ($epay_params as $key => $value)
		{
			$code .= '<input type="hidden" name="' . $key . '" value="' . $value . '" />' . "\n";
		}
	$code .= '<input type="submit" value="Open the ePay Payment Window">
	</form>';
	
	return $code;
}

function epay_refund($params)
{
	$amount = $params['amount'];
	
	$epay_params = array();
	$epay_params['merchantnumber'] = $params['merchantnumber'];
	$epay_params['transactionid'] = $params['transid'];
	$epay_params['amount'] = $amount * 100;
	$epay_params['pbsresponse'] = -1;
	$epay_params['epayresponse'] = -1;
	
	$soap = new SoapClient('https://ssl.ditonlinebetalingssystem.dk/remote/payment.asmx?WSDL');
	$soap_credit_result = $soap->credit($epay_params);
	
	if($soap_credit_result->creditResult == true)
	{
		return array("status" => "success", "transid" => $params["transid"], "rawdata" => $return);
	}
	elseif ($soap_credit_result->creditResult == false)
	{
		return array("status" => "declined", "rawdata" => $return);
	}
	else
	{
		return array("status" => "error", "rawdata" => $return);
	}
}
?>