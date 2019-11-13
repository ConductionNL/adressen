<?php
// src/Subscriber/AddresGetSubscriber.php

namespace App\Subscriber;

use ApiPlatform\Core\Exception\InvalidArgumentException;
use ApiPlatform\Core\EventListener\EventPriorities;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseForControllerResultEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Serializer\SerializerInterface;
use Doctrine\ORM\EntityManagerInterface;



use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use App\Entity\Adres;
use App\Service\KadasterService;

final class AdresGetSubscriber implements EventSubscriberInterface
{
	private $params;
	private $kadasterService;
	private $serializer;

	public function __construct(ParameterBagInterface $params, KadasterService $kadasterService, SerializerInterface $serializer)
	{
		$this->params = $params;
		$this->kadasterService= $kadasterService;
		$this->serializer= $serializer;
	}

	public static function getSubscribedEvents()
	{

		return [
				KernelEvents::VIEW => ['adres', EventPriorities::PRE_VALIDATE],
		];

	}

	public function adres(GetResponseForControllerResultEvent $event)
	{
		$route =  $event->getRequest()->get('_route');
		$method = $event->getRequest()->getMethod();


		// Lats make sure that some one posts correctly
		if (Request::METHOD_GET !== $method || $route != 'api_adres_get_collection') {
			return;
		}

		$huisnummer = (int) $event->getRequest()->query->get('huisnummer');
		$postcode = $event->getRequest()->query->get('postcode');
		$huisnummerToevoeging= $event->getRequest()->query->get('huisnummer_toevoeging');
		/* @deprecated */
		if(!$huisnummerToevoeging){
            $huisnummerToevoeging= $event->getRequest()->query->get('huisnummertoevoeging');
        }

		// Let clear up the postcode
		$postcode = preg_replace('/\s+/', '', $postcode);
		$postcode = strtoupper($postcode);
        $postcode = trim($postcode);

		// Even iets van basis valdiatie
		if(!$huisnummer || !is_int($huisnummer)){
			throw new InvalidArgumentException(sprintf('Invalid huisnummer: '.$huisnummer));
		}

		if(!$postcode || strlen($postcode) != 6){
			throw new InvalidArgumentException(sprintf('Invalid postcode: '.$postcode));
		}


		$adressen= $this->kadasterService->getAdresOnHuisnummerPostcode($huisnummer, $postcode);


		// If a huisnummer_toevoeging is provided we need to do some aditional filtering
		if($huisnummerToevoeging){
			$response["_links"]["self"] = "/adressen?huisnummer=".$huisnummer."&huisnummertoevoeging=".$huisnummerToevoeging."&postcode=".$postcode;

			// Lets loop trough the addreses to see if we have a match
			$filterdAdressen = [];
			foreach($adressen as $adres){
			    if(array_key_exists('huisnummertoevoeging', $adres) && preg_match( '/.*?'. strtolower($huisnummerToevoeging) .'.*?/' ,strtolower($adres['huisnummertoevoeging']))){
					$filterdAdressen[] = $adres;
				}
			}

			// we are only going to overide our initial result if we have more then one match
			if(count($filterdAdressen) > 0){
				$adressen= $filterdAdressen;
			}

		}

		// Let then create the responce
		$response = [];
		$response["adressen"] = $adressen;
		$response["_links"] = ["self"=>"/adressen?huisnummer=".$huisnummer."&postcode=".$postcode];
		$response["totalItems"] = count($adressen);
		$response["itemsPerPage"] =30;

		$json = $this->serializer->serialize(
				$response,
				'jsonhal',['enable_max_depth' => true]
				);

		$response = new Response(
			$json,
			Response::HTTP_OK,
			['content-type' => 'application/json+hal']
			);

		$event->setResponse($response);

		return;
	}

}
