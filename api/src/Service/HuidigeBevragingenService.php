<?php


namespace App\Service;


use App\Entity\Adres;
use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use Symfony\Component\Cache\Adapter\AdapterInterface as CacheInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

class HuidigeBevragingenService implements KadasterServiceInterface
{

    private Client $client;
    private ParameterBagInterface $params;
    private EntityManagerInterface $entityManager;
    private CacheInterface $cache;

    public function __construct(ParameterBagInterface $params, CacheInterface $cache, EntityManagerInterface $entityManager)
    {
        $this->params = $params;
        $this->cache = $cache;
        $this->entityManager = $entityManager;
        $this->client = new Client([
            // Base URI is used with relative requests
            'base_uri' => $this->params->get('common_ground.components')['bag']['location'],
            // You can set any number of default request options.
            'timeout' => 4000.0,
            // This api key needs to go into params
            'headers' => [
                'X-Api-Key' => $this->params->get('common_ground.components')['bag']['apikey'],
                'Accept-Crs'  => 'epsg:28992',
                ],
            'http_errors' => false,
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

    public function getSearchResults(string $search, int $page = 1): array
    {

        $item = $this->cache->getItem('searchResults_'.md5($search));
        if ($item->isHit()) {
            return $item->get();
        }
        $results = [];
        $response = $this->client->get('adressen/zoek', ['query' => $this->convertQuery(['zoek' => $search, 'pageSize' => 100, 'page' => $page])]);
        if($response->getStatusCode() != 200){
            throw new HttpException($response->getStatusCode(), $response->getReasonPhrase());
        }
        $response = json_decode($response->getBody()->getContents(), true);
        if(key_exists('_embedded', $response) && key_exists('zoekresultaten', $response['_embedded'])){
            $results = $response['_embedded']['zoekresultaten'];
        }
        if(
            key_exists('_links', $response) &&
            key_exists('self', $response['_links']) &&
            key_exists('last', $response['_links']) &&
            $response['_links']['self'] != $response['_links']['last']
        ){
            $results = array_merge($results, $this->getSearchResults($search, $page + 1));
        }
        $item->set($results);
        $item->expiresAt(new \DateTime('tomorrow 4:59'));
        $this->cache->save($item);

        return $results;
    }

    public function getAddressableObject(string $id): array
    {
        $item = $this->cache->getItem('addressableObject'.md5($id));
        if ($item->isHit()) {
            return $item->get();
        }
        $response = $this->client->get("adresseerbareobjecten/$id");
        if($response->getStatusCode() != 200){
            throw new HttpException($response->getStatusCode(), $response->getReasonPhrase());
        }
        $result = json_decode($response->getBody()->getContents(), true);

        $item->set($result);
        $item->expiresAt(new \DateTime('tomorrow 4:59'));
        $this->cache->save($item);

        return $result;
    }
    public function getObject(array $addressArray): Adres
    {
        if(!key_exists('adresseerbaarObjectIdentificatie', $addressArray)){
            var_dump($addressArray);
            die;
        }
        $addressableObject = $this->getAddressableObject($addressArray['adresseerbaarObjectIdentificatie']);
        $address = new Adres();
        $address->setId($addressArray['nummeraanduidingIdentificatie']);
        $address->setType($addressableObject['type']);
        if (array_key_exists('postcode', $addressArray)) {
            $address->setPostcode($addressArray['postcode']);
        }
        if (array_key_exists('huisnummer', $addressArray)) {
            $address->setHuisnummer($addressArray['huisnummer']);
        }
        $suffix = !key_exists('huisletter', $addressArray) ?
            (!key_exists('huisnummertoevoeging', $addressArray) ? null : $addressArray['huisnummertoevoeging']) :
            (!key_exists('huisnummertoevoeging', $addressArray) ? $addressArray['huisletter'] : "{$addressArray['huisletter']} {$addressArray['huisnummertoevoeging']}");
        $address->setHuisnummertoevoeging($suffix);
        if (array_key_exists('straat', $addressArray)) {
            $address->setStraat($addressArray['straat']);
        }
        if(key_exists('oppervlakte', $addressableObject)){
            $address->setOppervlakte($addressableObject['oppervlakte']);
        }

        $address->setWoonplaats($addressArray['woonplaats']);
        $address->setWoonplaatsNummer($addressArray['woonplaatsIdentificatie']);

        $links = [];
        $links['bijbehorendeOpenbareRuime']['href'] = $addressArray['_links']['openbareRuimte'];
        $links['bijbehorendeWoonplaats']['href'] = $addressArray['_links']['woonplaats'];
        $links['adresseerbaarObject']['href'] = $addressArray['_links']['adresseerbaarObject'];
        $links['nummeraanduiding']['href'] = $addressArray['_links']['nummeraanduiding'];

        $address->setLinks($links);

        $address->setStatusNummeraanduiding($addressArray['_embedded']['nummeraanduiding']['status']);
        $address->setStatusOpenbareRuimte($addressArray['_embedded']['openbareRuimte']['status']);
        $address->setStatusWoonplaats($addressArray['_embedded']['woonplaats']['status']);
        $address->setStatusVerblijfsobject($addressableObject['status']);

        $this->entityManager->persist($address);
        $address->setId($addressArray['nummeraanduidingIdentificatie']);
        $this->entityManager->persist($address);

        return $address;
    }

    public function getAddress($searchResult): array
    {
        $item = $this->cache->getItem('address'.md5($searchResult['identificatie']));
        if ($item->isHit()) {
            return $item->get();
        }
        $response = $this->client->get('adressen', ['query' => $this->convertQuery(['zoekresultaatIdentificatie' => $searchResult['identificatie'], 'expand' => 'openbareRuimte,nummeraanduiding,woonplaats'])]);
        if($response->getStatusCode() != 200){
            throw new HttpException($response->getStatusCode(), $response->getReasonPhrase());
        }
        $response = json_decode($response->getBody()->getContents(), true);

        $item->set($response);
        $item->expiresAt(new \DateTime('tomorrow 4:59'));
        $this->cache->save($item);

        return $response;
    }

    public function getAdressesFromSearchResults (array $searchResults): array
    {
        $results = [];
        foreach($searchResults as $searchResult){
            $response = $this->getAddress($searchResult);

            if(key_exists('_embedded', $response) && key_exists('adressen', $response['_embedded'])){
                foreach($response['_embedded']['adressen'] as $address){
                    if(!key_exists('nummeraanduidingIdentificatie', $address)){
                        continue;
                    }
                    $results[] = $this->getObject($address);
                }
            }
        }
        return $results;
    }

    public function getAdresOnBagId(string $bagId): Adres
    {
        $response = $this->client->get("adressen/$bagId");
        if($response->getStatusCode() != 200){
            throw new HttpException($response->getStatusCode(), $response->getReasonPhrase());
        }
        $response = json_decode($response->getBody()->getContents(), true);
        return $this->getObject($response);
    }

    public function getAdresOnHuisnummerPostcode($huisnummer, $postcode): array
    {
        $search = "$huisnummer $postcode";
        return $this->getAdressesFromSearchResults($this->getSearchResults($search));
    }
}
