<?php

namespace App\Service;

use App\Entity\Adres;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use Symfony\Component\Cache\Adapter\AdapterInterface as CacheInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class IndividueleBevragingenService implements KadasterServiceInterface
{
    private Client $client;
    private ParameterBagInterface $params;
    private EntityManagerInterface $entityManager;
    private CacheInterface $cache;

    public function __construct(ParameterBagInterface $params, CacheInterface $cache, EntityManagerInterface $entityManager)
    {
        $this->params = $params;
        $this->entityManager = $entityManager;
        $this->cache = $cache;
        $this->client = new Client(
            [
                // Base URI is used with relative requests
                'base_uri' => $this->params->get('components')['bag']['location'],
                // You can set any number of default request options.
                'timeout' => 4000.0,
                // This api key needs to go into params
                'headers' => [
                    'X-Api-Key'   => $this->params->get('components')['bag']['apikey'],
                    'Accept-Crs'  => 'epsg:28992',
                ],
                // Base URI is used with relative requests
                //            'http_errors' => false,
            ]
        );
    }

    private function convertQuery($query): string
    {
        if (is_array($query) && $query != []) {
            $queryString = '';
            $iterator = 0;
            foreach ($query as $parameter => $value) {
                $queryString .= "$parameter=$value";

                $iterator++;
                if ($iterator < count($query)) {
                    $queryString .= '&';
                }
            }
            $query = $queryString;
        } elseif ($query == []) {
            $query = '';
        }

        return $query;
    }

    public function getAddressableObject(string $numberedObjectId): array
    {
        $item = $this->cache->getItem('addressableObject'.md5($numberedObjectId));
        if ($item->isHit()) {
            return $item->get();
        }

        $response = $this->client->get('adresseerbareobjecten', ['query' => $this->convertQuery(['nummeraanduidingIdentificatie' => $numberedObjectId])]);
        if ($response->getStatusCode() != 200) {
            throw new HttpException($response->getStatusCode(), $response->getReasonPhrase());
        }
        $response = json_decode($response->getBody()->getContents(), true);
        if (
            key_exists('_embedded', $response) &&
            key_exists('adresseerbareObjecten', $response['_embedded']) &&
            count($response['_embedded']['adresseerbareObjecten']) > 0
        ) {
            foreach ($response['_embedded']['adresseerbareObjecten'][0] as $key => $value) {
                if ($key != '_links') {
                    $item->set($value);
                    $item->expiresAt(new \DateTime('tomorrow 4:59'));
                    $this->cache->save($item);

                    return $value;
                }
            }
        }

        throw new HttpException(404, 'The addressable object for this numbered object has not been found');
    }

    public function getObject(array $addressArray): Adres
    {
        $addressableObject = $this->getAddressableObject($addressArray['nummeraanduiding']['identificatie']);
        $address = new Adres();
        $address->setId($addressArray['nummeraanduiding']['identificatie']);
        $address->setType(strtolower($addressArray['nummeraanduiding']['typeAdresseerbaarObject']));
        if (array_key_exists('postcode', $addressArray['nummeraanduiding'])) {
            $address->setPostcode($addressArray['nummeraanduiding']['postcode']);
        }
        if (array_key_exists('huisnummer', $addressArray['nummeraanduiding'])) {
            $address->setHuisnummer($addressArray['nummeraanduiding']['huisnummer']);
        }
        $suffix = !key_exists('huisletter', $addressArray['nummeraanduiding']) ?
            (!key_exists('huisnummertoevoeging', $addressArray['nummeraanduiding']) ? null : $addressArray['nummeraanduiding']['huisnummertoevoeging']) :
            (!key_exists('huisnummertoevoeging', $addressArray['nummeraanduiding']) ? $addressArray['nummeraanduiding']['huisletter'] : "{$addressArray['nummeraanduiding']['huisletter']} {$addressArray['nummeraanduiding']['huisnummertoevoeging']}");
        $address->setHuisnummertoevoeging($suffix);
        if (
            array_key_exists('_embedded', $addressArray) &&
            key_exists('ligtAanOpenbareRuimte', $addressArray['_embedded']) &&
            key_exists('openbareRuimte', $addressArray['_embedded']['ligtAanOpenbareRuimte']) &&
            key_exists('naam', $addressArray['_embedded']['ligtAanOpenbareRuimte']['openbareRuimte'])
        ) {
            $address->setStraat($addressArray['_embedded']['ligtAanOpenbareRuimte']['openbareRuimte']['naam']);
        }
        if ($address->getType() == 'verblijfsobject') {
            $address->setOppervlakte($addressableObject[$address->getType()]['oppervlakte']);
        }

        $address->setWoonplaats($addressArray['_embedded']['ligtInWoonplaats']['woonplaats']['naam']);
        $address->setWoonplaatsNummer($addressArray['_embedded']['ligtInWoonplaats']['woonplaats']['identificatie']);

        $links = [];
        $links['bijbehorendeOpenbareRuime']['href'] = $addressArray['_links']['ligtAanOpenbareRuimte'];
        $links['bijbehorendeWoonplaats']['href'] = $addressArray['_links']['ligtInWoonplaats'];
        $links['adresseerbaarObject']['href'] = $addressableObject['_links']['self'];
        $links['nummeraanduiding']['href'] = $addressableObject['_links']['heeftAlsHoofdAdres'];

        $address->setLinks($links);

        $address->setStatusNummeraanduiding($addressArray['nummeraanduiding']['status']);
        $address->setStatusOpenbareRuimte($addressArray['_embedded']['ligtAanOpenbareRuimte']['openbareRuimte']['status']);
        $address->setStatusWoonplaats($addressArray['_embedded']['ligtInWoonplaats']['woonplaats']['status']);
        $address->setStatusVerblijfsobject($addressableObject[$address->getType()]['status']);

        $this->entityManager->persist($address);
        $address->setId($addressArray['nummeraanduiding']['identificatie']);
        $this->entityManager->persist($address);

        return $address;
    }

    public function getNumberObjects(string $postcode, int $huisnummer, int $page = 1): array
    {
        $item = $this->cache->getItem('numberObjects'.md5("postcode=$postcode&huisnummer=$huisnummer"));
        if ($item->isHit()) {
            return $item->get();
        }
        $query = $this->convertQuery(['huisnummer' => $huisnummer, 'postcode' => $postcode, 'page' => $page, 'pageSize' => 100, 'expand' => 'ligtAanOpenbareRuimte,ligtInWoonplaats', 'huidig' => true]);
        $results = [];
        $response = $this->client->get('nummeraanduidingen', ['query' => $query]);
        if ($response->getStatusCode() != 200) {
            throw new HttpException($response->getStatusCode(), $response->getReasonPhrase());
        }
        $response = json_decode($response->getBody()->getContents(), true);
        if (key_exists('_embedded', $response) && key_exists('nummeraanduidingen', $response['_embedded'])) {
            $results = $response['_embedded']['nummeraanduidingen'];
        }
        if (
            key_exists('_links', $response) &&
            key_exists('self', $response['_links']) &&
            key_exists('last', $response['_links']) &&
            $response['_links']['self'] != $response['_links']['last']
        ) {
            $results = array_merge($results, $this->getNumberObjects($postcode, $huisnummer, $page + 1));
        }
        $item->set($results);
        $item->expiresAt(new \DateTime('tomorrow 4:59'));
        $this->cache->save($item);

        return $results;
    }

    public function getAdresOnBagId(string $bagId): Adres
    {
        $item = $this->cache->getItem('numberObject'.md5($bagId));
        if ($item->isHit()) {
            return $item->get();
        }
        $response = $this->client->get("nummeraanduidingen/$bagId", ['query' => $this->convertQuery(['expand' => 'ligtAanOpenbareRuimte,ligtInWoonplaats'])]);
        if ($response->getStatusCode() != 200) {
            throw new HttpException($response->getStatusCode(), $response->getReasonPhrase());
        }
        $response = json_decode($response->getBody()->getContents(), true);

        $address = $this->getObject($response);
        $item->set($address);
        $item->expiresAt(new \DateTime('tomorrow 4:59'));
        $this->cache->save($item);

        return $address;
    }

    public function getAdresOnHuisnummerPostcode($huisnummer, $postcode): array
    {
        $addresses = $this->getNumberObjects($postcode, $huisnummer);
        $results = [];
        foreach ($addresses as $address) {
            $results[] = $this->getObject($address);
        }

        return $results;
    }

    public function getAddressForSearchResult(string $searchResultId): ?Adres
    {
        $item = $this->cache->getItem('addressSearch'.md5($searchResultId));
        if ($item->isHit()) {
            return $item->get();
        }
        $response = $this->client->get("adressen", ['query' => "zoekresultaatIdentificatie=$searchResultId"]);
        if ($response->getStatusCode() != 200) {
            throw new HttpException($response->getStatusCode(), $response->getReasonPhrase());
        }
        $response = json_decode($response->getBody()->getContents(), true);
        if(!isset($response['_embedded'])){
            throw new NotFoundHttpException('No address found for given parameters');
        }
        foreach($response['_embedded']['adressen'] as $address){
            $address = $this->getAdresOnBagId($address['nummeraanduidingIdentificatie']);
            $item->set($address);
            $item->expiresAt(new \DateTime('tomorrow 4:59'));
            $this->cache->save($item);
            return $address;
        }
        return null;
    }

    public function getAdresOnStraatnaamHuisnummerPlaatsnaam(string $street, string $houseNumber, ?string $houseNumberSuffix = null, string $locality): array
    {
        $response = $this->client->get("adressen/zoek", ['query' => "zoek=$street $houseNumber$houseNumberSuffix $locality"]);
        if ($response->getStatusCode() != 200) {
            throw new HttpException($response->getStatusCode(), $response->getReasonPhrase());
        }
        $response = json_decode($response->getBody()->getContents(), true);

        if(!isset($response['_embedded'])){
            var_dump($response);
            throw new NotFoundHttpException('No address found for given parameters');
        }

        $results = [];
        foreach($response['_embedded']['zoekresultaten'] as $result){
            $address =  $this->getAddressForSearchResult($result['identificatie']);
            $address ? $results[] = $address : null;
        }

        return $results;
    }
}
