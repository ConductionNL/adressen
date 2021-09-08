<?php

// src/Subscriber/AddresGetSubscriber.php

namespace App\Subscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use App\Entity\Adres;
use App\Service\HuidigeBevragingenService;
use App\Service\IndividueleBevragingenService;
use App\Service\KadasterService;
use App\Service\KadasterServiceInterface;
use Conduction\CommonGroundBundle\Service\SerializerService;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\SerializerInterface;

final class AdresGetSubscriber implements EventSubscriberInterface
{
    private ParameterBagInterface $parameterBag;
    private KadasterServiceInterface $kadasterService;
    private SerializerService $serializerService;

    public function __construct(ParameterBagInterface $parameterBag, KadasterService $kadasterService, HuidigeBevragingenService $huidigeBevragingenService, IndividueleBevragingenService $individueleBevragingenService, SerializerInterface $serializer)
    {
        $this->parameterBag = $parameterBag;

        if ($this->parameterBag->get('components')['bag']['location'] == 'https://bag.basisregistraties.overheid.nl/api/v1/') {
            $this->kadasterService = $kadasterService;
        } elseif (
            $this->parameterBag->get('components')['bag']['location'] == 'https://api.bag.acceptatie.kadaster.nl/lvbag/individuelebevragingen/v2/' ||
            $this->parameterBag->get('components')['bag']['location'] == 'https://api.bag.kadaster.nl/lvbag/individuelebevragingen/v2/'
        ) {
            $this->kadasterService = $individueleBevragingenService;
        } else {
            $this->kadasterService = $huidigeBevragingenService;
        }
        $this->serializer = $serializer;
        $this->serializerService = new SerializerService($serializer);
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST => ['adres', EventPriorities::PRE_DESERIALIZE],
        ];
    }

    public function adres(RequestEvent $event)
    {
        $path = explode('/', parse_url($event->getRequest()->getUri())['path']);
        $route = $event->getRequest()->attributes->get('_route');
        $method = $event->getRequest()->getMethod();

        // Lats make sure that some one posts correctly
        if (Request::METHOD_GET !== $method || ($route != 'api_adres_get_collection' && !in_array('adressen', $path))) {
            return;
        }
        try{
            if (($route != 'api_adres_get_collection' && in_array('adressen', $path)) || ($route == 'api_adres_get_collection' && $event->getRequest()->query->has('bagid'))) {
                $this->getAddressOnBagId($event);
            } else {
                $this->getAddressOnSearchParameters($event);
            }
        } catch (BadRequestHttpException|NotFoundHttpException $e){
            $event->setResponse(new Response(
                $e->getMessage(),
                $e->getStatusCode())
            );
        }
    }

    public function getAddressOnBagId(RequestEvent $event): void
    {

        $path = explode('/', parse_url($event->getRequest()->getUri())['path']);
        if (!$event->getRequest()->query->has('bagid')) {
            $bagId = end($path);
        } else {
            $bagId = $event->getRequest()->query->get('bagid');
        }
        $adres = $this->kadasterService->getAdresOnBagId($bagId);

        $this->serializerService->setResponse($adres, $event);
    }

    public function getHuisnummerToevoeging(RequestEvent $event): ?string
    {
        $huisnummerToevoeging = $event->getRequest()->query->get('huisnummer_toevoeging', $event->getRequest()->query->get('huisnummertoevoeging'));
        if ($huisnummerToevoeging && str_replace(' ', '', $huisnummerToevoeging) == '') {
            $huisnummerToevoeging = null;
        }
        return $huisnummerToevoeging;
    }

    public function getPostcode(RequestEvent $event): ?string
    {
        $postcode = $event->getRequest()->query->get('postcode');

        if(!$postcode){
            return $postcode;
        }
        // Let clear up the postcode
        $postcode = preg_replace('/\s+/', '', $postcode);
        $postcode = strtoupper($postcode);
        $postcode = trim($postcode);

        return $postcode;
    }

    public function getRenderType(RequestEvent $event): string
    {
        $contentType = $event->getRequest()->headers->get('accept');
        if (!$contentType) {
            $contentType = $event->getRequest()->headers->get('Accept');
        }
        switch ($contentType) {
            case 'application/json':
                $renderType = 'json';
                break;
            case 'application/ld+json':
                $renderType = 'jsonld';
                break;
            case 'application/hal+json':
                $renderType = 'jsonhal';
                break;
            default:
                $renderType = 'jsonhal';
        }
        return $renderType;
    }

    public function serializeArray(array $array, RequestEvent $event): ArrayCollection
    {
        switch ($this->getRenderType($event)) {
            case 'jsonld':
                $response['@context'] = '/contexts/Adres';
                $response['@id'] = '/adressen';
                $response['@type'] = 'hydra:Collection';
                $response['hydra:member'] = $array;
                $response['hydra:totalItems'] = count($array);
                break;
            default:
                $response['adressen'] = $array;
                $response['totalItems'] = count($array);
                $response['itemsPerPage'] = count($array);
                $response['_links'] = $response['_links'] = ['self' => "/adressen?{$event->getRequest()->getQueryString()}"];
                break;
        }

        return new ArrayCollection($response);
    }

    public function filterHouseNumberSuffix(array $addresses, string $houseNumberSuffix): array
    {
        $results = [];
        foreach ($addresses as $address) {
            if (
                $address instanceof Adres &&
                str_replace(' ', '', strtolower($address->getHuisnummertoevoeging())) == str_replace(' ', '', strtolower($houseNumberSuffix)) ||
                strpos(str_replace(' ', '', strtolower($address->getHuisnummertoevoeging())), str_replace(' ', '', strtolower($houseNumberSuffix))) !== false
            ) {
                $results[] = $address;
            }
        }
        return $results;
    }

    public function getAddressOnSearchParameters(RequestEvent $event): void
    {
        $huisnummer = (int) $event->getRequest()->query->get('huisnummer');
        $huisnummerToevoeging = $this->getHuisnummerToevoeging($event);
        $postcode = $this->getPostcode($event);
        $straat = $event->getRequest()->query->get('straatnaam');
        $woonplaats = $event->getRequest()->query->get('woonplaats');
        $bagId = $event->getRequest()->query->get('bagid');

        if($bagId){
            $result = $this->kadasterService->getAdresOnBagId($bagId);
        } elseif($huisnummer && $postcode){
            $result = $this->kadasterService->getAdresOnHuisnummerPostcode($huisnummer, $postcode);
            if($huisnummerToevoeging){
                $result = $this->serializeArray($this->filterHouseNumberSuffix($result, $huisnummerToevoeging), $event);
            } else {
                $result = $this->serializeArray($result, $event);
            }
        } elseif($huisnummer && $straat && $woonplaats) {
            $result = $this->serializeArray($this->kadasterService->getAdresOnStraatnaamHuisnummerPlaatsnaam($straat, $huisnummer, $huisnummerToevoeging, $woonplaats), $event);
        } else {
            throw new BadRequestHttpException("Not enough data to find the address. The following combinations of query parameters are valid:\npostcode and huisnummer\nbagid\nstraatnaam, woonplaats and huisnummer");
        }
        $this->serializerService->setResponse($result, $event);
    }
}
