<?php
class Axa implements WsInterface
{
	private $client;

	public function __construct($wsConfig, $paypeApi)
	{
		$this->client = new SoapClient($wsConfig['location']);
	}

	public function getCustomers($lastSyncCustomerId)
	{
		// TODO: not implemented
		$customers = array();
		return $customers;
	}

	public function postCustomers($customers)
	{
		foreach($customers as $c)
		{
			$axaXml = '<?xml version="1.0" encoding="UTF-8"?><E-Document><Document>';

			$address = json_decode($c->meta_data);

			$axaCustomer = array();
			$axaCustomer['Name'] = $c->first_name;
			$axaCustomer['Surname'] = $c->last_name;
			$axaCustomer['RegNum'] = $c->customer_id;
			$axaCustomer['ContactData'] = array(
				'Phone' => $c->phone_number,
				'PhoneCountryCode' => $c->phone_country,
				'EmailAddress' => $c->email,
				'Address' => array(
					'Country' => 'Eesti',
					'CountryCode' => 'EE',
					'County' => (!empty($address->county)?$address->county:'puudub'),
					'Street' => (!empty($address->street)?$address->street:'puudub'),
					'City' => (!empty($address->city)?$address->city:'puudub'),
					'PostalCode' => (!empty($address->zip)?$address->zip:'0'),
					'HouseNo' => (!empty($address->house)?$address->house:'0'),
					'ApartmentNo' => (!empty($address->apartment)?$address->apartment:'0')
				)
			);

			$axaCustomer['AcceptsDataProcessing'] = 'Jah';
			$axaCustomer['AcceptsSpecialOffers'] = 'Jah';
			$axaCustomer['PaypeId'] = $c->token;
			$axaCustomer['CustAccount'] = null;
			$axaCustomer['VATRegNum'] = null;
			$axaCustomer['Currency'] = 'EUR';
			$axaCustomer['CustType'] = 'ERAISIK';

			$axaXml .= '<DocumentCust>' . $this->arrayToXml($axaCustomer) . '</DocumentCust>';

			$axaXml .= '</Document></E-Document>';

			try
			{
				$response = $this->client->__soapCall('CallXMLAction', array(array('action'=>'createClient_MCC','xml'=>$axaXml)));
				paypeLog('axa-res-debug > ' . json_encode($response) . ' client > ' . json_encode($axaCustomer));
			}
			catch(Exception $e)
			{
				paypeLog('axa-error > ' . $e->getMessage() . ' client > ' . json_encode($axaCustomer));
			}
		}
	}

	private function arrayToXml($array)
	{
		$xml = '';
		foreach($array as $key=>$value)
		{
			if(is_array($value))
			{
				$value = $this->arrayToXml($value);
			}
			$xml .= '<'.$key.'>'.$value.'</'.$key.'>';
		}

		return $xml;
	}
}