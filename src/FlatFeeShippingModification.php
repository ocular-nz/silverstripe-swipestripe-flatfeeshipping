<?php

namespace FlatFeeShipping;

use Addresses\Country_Shipping;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\View\Requirements;
use SwipeStripe\Order\Modification;

class FlatFeeShippingModification extends Modification
{
	private static $table_name = 'FlatFeeShippingModification';

	private static $has_one = array(
		'FlatFeeShippingRate' => FlatFeeShippingRate::class
	);

	private static $defaults = array(
		'SubTotalModifier' => true,
		'SortOrder' => 50
	);

	private static $default_sort = 'SortOrder ASC';

	public function add($order, $value = null)
	{
		/** @var LoggerInterface $logger */
		$logger = Injector::inst()->get(LoggerInterface::class);

		$this->OrderID = $order->ID;

		$country = Country_Shipping::get()
			->filter("Code", $order->ShippingCountryCode)
			->first();

		if (!empty($country)) {
			$rates = $this->getFlatShippingRates($country);
		}
		if (!$order->IsPickUp && isset($rates) && $rates->exists()) {
			$postcode = $order->ShippingPostalCode;

			$postcodes = $this->config()->get('postcodes');

			$location = 'Rest of NZ';
			foreach ($postcodes as $loc => $codes) {
				if (in_array($postcode, $codes)) {
					$location = $loc;
					break;
				}
			}

			//Pick the rate
			$rate = $rates->find('Title', $location);

			if (!$rate || !$rate->exists()) {
				$rate = $rates->first();
			}

			$existingMods = FlatFeeShippingModification::get()->filter("OrderID", $order->ID);
			if ($existingMods->exists()) {
				$logger->debug("Shipping Modifier already exists. Deleting. Order #" . $order->ID, []);
				$existingMods->each(fn (FlatFeeShippingModification $mod) => $mod->delete());
			}


			//Generate the Modification now that we have picked the correct rate
			$mod = new FlatFeeShippingModification();

			$mod->Price = $rate->Amount()->getAmount();

			$mod->Description = $rate->Description;
			$mod->OrderID = $order->ID;
			$mod->Value = $rate->ID;
			$mod->FlatFeeShippingRateID = $rate->ID;
			$mod->write();

			/** @var LoggerInterface $logger */
			$logger->debug("Created Shipping Modification. ID: " . $mod->ID . " Order: " . $order->ID . " Price: " . $mod->Price, []);
		}
	}

	public function getRate($postcode)
	{
		
	}

	public function getFlatShippingRates(Country_Shipping $country)
	{
		//Get valid rates for this country
		$countryID = ($country && $country->exists()) ? $country->ID : null;
		$rates = FlatFeeShippingRate::get()->filter("CountryID", $countryID);
		$this->extend("updateFlatShippingRates", $rates, $country);
		return $rates;
	}

	public function getFormFields()
	{

		$fields = new FieldList();
		$rate = $this->FlatFeeShippingRate();
		$rates = $this->getFlatShippingRates($rate->Country());

		if ($rates && $rates->exists()) {

			if ($rates->count() > 1) {
				$field = FlatFeeShippingModifierField_Multiple::create(
					$this,
					_t('FlatFeeShippingModification.FIELD_LABEL', 'Shipping'),
					$rates->map('ID', 'Label')->toArray()
				)->setValue($rate->ID);
			} else {
				$newRate = $rates->first();
				$field = FlatFeeShippingModifierField::create(
					$this,
					$newRate->Title,
					$newRate->ID
				)->setAmount($newRate->Price());
			}

			$fields->push($field);
		}

		if (!$fields->exists()) Requirements::javascript('swipestripe-flatfeeshipping/javascript/FlatFeeShippingModifierField.js');

		return $fields;
	}
}
