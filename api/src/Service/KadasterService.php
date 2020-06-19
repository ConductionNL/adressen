<?php

// Conduction/CommonGroundBundle/Service/KadasterService.php

/*
 * This file is part of the Conduction Common Ground Bundle
 *
 * (c) Conduction <info@conduction.nl>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use App\Entity\Adres;
//use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use Symfony\Component\Cache\Adapter\AdapterInterface as CacheInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class KadasterService
{
    private $config;
    private $params;
    private $client;
    private $commonGroundService;
    private $manager;
    /**
     * @var CacheInterface
     */
    private $cache;

    public function __construct(ParameterBagInterface $params, CacheInterface $cache, EntityManagerInterface $manager)
    {
        $this->params = $params;
        $this->cache = $cache;
        $this->manager = $manager;

        $this->client = new Client([
            // Base URI is used with relative requests
            'base_uri' => $this->params->get('common_ground.components')['bag']['location'],
            // You can set any number of default request options.
            'timeout'  => 4000.0,
            // This api key needs to go into params
            'headers' => ['X-Api-Key' => $this->params->get('common_ground.components')['bag']['apikey']],
        ]);
    }

    public function setConfig($config)
    {
        $this->config = $config;
    }

    public function getNummeraanduidingen($query)
    {
        // Lets first try the cach
//        $item = $this->cache->getItem('nummeraanduidingen_'.md5($query));
//        if ($item->isHit()) {
//            return $item->get();
//        }

        $response = $this->client->request('GET', 'nummeraanduidingen', [
            'query' => $query,
        ]);
        $response = json_decode($response->getBody(), true);
        $nummeraanduidingen = $response['_embedded'];
        while(key_exists("_links", $response)
            && key_exists("next", $response['_links'])
            && key_exists("href", $response['_links']['next'])
        ){
            $response = json_decode($this->client->request('GET',$response['_links']['next']['href'])->getBody(), true);
            $nummeraanduidingen['nummeraanduidingen'] = array_merge($nummeraanduidingen['nummeraanduidingen'], $response['_embedded']['nummeraanduidingen']);
        }
        $this->cache->save($nummeraanduidingen);

        return $nummeraanduidingen;
    }

    public function getNummeraanduiding($id)
    {
        // Lets first try the cach
        $item = $this->cache->getItem('nummeraanduiding_'.md5($id));
        if ($item->isHit()) {
            return $item->get();
        }

        $response = $this->client->request('GET', 'nummeraanduidingen/'.$id);
        $response = json_decode($response->getBody(), true);

        $item->set($response);
        $item->expiresAt(new \DateTime('tomorrow'));
        $this->cache->save($item);

        return $item->get();
    }

    public function getWoonplaatsens($query)
    {
        $response = $this->client->request('GET', 'woonplaatsen', [
            'query' => $query,
        ]);
        $response = json_decode($response->getBody(), true);

        return $response['_embedded'];
    }

    public function getWoonplaats($id)
    {
        // Lets first try the cach
        $item = $this->cache->getItem('woonplaats_'.md5($id));
        if ($item->isHit()) {
            return $item->get();
        }

        $response = $this->client->request('GET', 'woonplaatsen/'.$id);
        $response = json_decode($response->getBody(), true);

        // Save to cash
        $item->set($response);
        $item->expiresAt(new \DateTime('January 1st Next Year')); // By law dutch localities only change in Januray the first
        $this->cache->save($item);

        return $item->get();
    }

    public function getOpenbareruimtes($query)
    {
        // Lets first try the cach
        $response = $this->client->request('GET', 'openbare-ruimtes', [
            'query' => $query,
        ]);
        $response = json_decode($response->getBody(), true);

        return $response['_embedded'];
    }

    public function getOpenbareruimte($id)
    {
        // Lets first try the cach
        $item = $this->cache->getItem('openbare-ruimte_'.md5($id));
        if ($item->isHit()) {
            return $item->get();
        }

        $response = $this->client->request('GET', 'openbare-ruimtes/'.$id);
        $response = json_decode($response->getBody(), true);

        // Save to cach
        $item->set($response);
        $item->expiresAfter(3600);
        $this->cache->save($item);

        return $item->get();
    }

    public function getPanden($query)
    {
        $response = $this->client->request('GET', 'panden', [
            'query' => $query,
        ]);
        $response = json_decode($response->getBody(), true);

        return $response['_embedded'];
    }

    public function getPand($id)
    {
        // Lets first try the cach
        $item = $this->cache->getItem('pand_'.md5($id));
        if ($item->isHit()) {
            return $item->get();
        }

        $response = $this->client->request('GET', 'panden/'.$id);
        $response = json_decode($response->getBody(), true);

        // Save to cach
        $item->set($response);
        $item->expiresAfter(3600);
        $this->cache->save($item);

        return $item->get();
    }

    public function getVerblijfsobjecten($query)
    {
        $response = $this->client->request('GET', 'verblijfsobjecten', [
            'query' => $query,
        ]);
        $response = json_decode($response->getBody(), true);

        return $response['_embedded'];
    }

    public function getVerblijfsobject($id)
    {
        // Lets first try the cach
        $item = $this->cache->getItem('verblijfsobject_'.md5($id));
        if ($item->isHit()) {
            return $item->get();
        }

        $response = $this->client->request('GET', 'verblijfsobjecten/'.$id);
        $response = json_decode($response->getBody(), true);

        // Save to cach
        $item->set($response);
        $item->expiresAfter(3600);
        $this->cache->save($item);

        return $item->get();
    }

    public function getLigplaatsen($query)
    {
        $response = $this->client->request('GET', 'ligplaatsen', [
            'query' => $query,
        ]);
        $response = json_decode($response->getBody(), true);

        return $response['_embedded'];
    }

    public function getLigplaats($id)
    {
        // Lets first try the cach
        $item = $this->cache->getItem('ligplaats_'.md5($id));
        if ($item->isHit()) {
            return $item->get();
        }

        $response = $this->client->request('GET', 'ligplaatsen/'.$id);
        $response = json_decode($response->getBody(), true);

        // Save to cach
        $item->set($response);
        $item->expiresAfter(3600);
        $this->cache->save($item);

        return $item->get();
    }

    public function getStandplaatsen($query)
    {
        $response = $this->client->request('GET', 'standplaatsen', [
            'query' => $query,
        ]);
        $response = json_decode($response->getBody(), true);

        return $response['_embedded'];
    }

    public function getStandplaats($id)
    {
        // Lets first try the cach
        $item = $this->cache->getItem('standplaats_'.md5($id));
        if ($item->isHit()) {
            return $item->get();
        }

        $response = $this->client->request('GET', 'standplaatsen/'.$id);
        $response = json_decode($response->getBody(), true);

        // Save to cach
        $item->set($response);
        $item->expiresAfter(3600);
        $this->cache->save($item);

        return $item->get();
    }

    //
    public function analyseUri($uri)
    {
        // Add trycatch
        $url = parse_url($uri);

        // Lets see if we can get an id
        $pathparts = explode('/', $url['path']);
        // We should check for a valid uuid and if so return an separate ID and endpoint
        //if(){
        // The id should be te last path part
        $url['id'] = end($pathparts);
        // The endpoint should be the path bart before the last part
        $url['endpoint'] = prev($pathparts);
        //}
        return $url;
    }

    // Somedoc block here
    public function getObject($nummeraanduiding) : Adres
    {
        $adres = new Adres();


        $adresseerbaarObject = $this->analyseUri($nummeraanduiding['_links']['adresseerbaarObject']['href']);

        // Let see what we got here in terms of object
        switch ($adresseerbaarObject['endpoint']) {
            case 'verblijfsobjecten':
                $nummeraanduiding['adres'] = $this->getVerblijfsobject($adresseerbaarObject['id']);
                $adres->setType('verblijfsobject');
                $responce['oppervlakte'] = $nummeraanduiding['adres']['oppervlakte'];
                break;
            case 'ligplaatsen':
                $nummeraanduiding['adres'] = $this->getLigplaats($adresseerbaarObject['id']);
                $adres->setType('ligplaats');
                break;
            case 'standplaatsen':
                $nummeraanduiding['adres'] = $this->getStandplaats($adresseerbaarObject['id']);
                $adres->setType('standplaats');
                break;
        }

        // Lets copy the following information if it exsists

        if (array_key_exists('huisnummer', $nummeraanduiding)) {
            $adres->setHuisnummer($nummeraanduiding['huisnummer']);
        }

        if (array_key_exists('huisnummertoevoeging', $nummeraanduiding)) {
            $responce['huisnummertoevoeging'] = $nummeraanduiding['huisnummertoevoeging'];
        }
        if (array_key_exists('huisletter', $nummeraanduiding)) {
            $responce['huisletter'] = $nummeraanduiding['huisletter'];
        }
        if (array_key_exists('postcode', $nummeraanduiding)) {
            $adres->setPostcode($nummeraanduiding['postcode']);
        }

        // We want return a single housenumber suffix
        if (!array_key_exists('huisnummertoevoeging', $responce) && array_key_exists('huisletter', $responce)) {
            $adres->setHuisnummerToevoeging($responce['huisletter']);
            unset($responce['huisletter']);
        } elseif (array_key_exists('huisnummertoevoeging', $responce) && array_key_exists('huisletter', $responce)) {
            /* @todo uitzoeken of deze samentrekking conform de norm is */
            $adres->setHuisnummerToevoeging($responce['huisletter'].' '.$responce['huisnummertoevoeging']);
            unset($responce['huisletter']);
        }

        // Then the apropriote openbare ruimte
        $links['bijbehorendeOpenbareRuimte']['href'] = $nummeraanduiding['_links']['bijbehorendeOpenbareRuimte']['href'];
        $bijbehorendeOpenbareRuimte = $this->analyseUri($links['bijbehorendeOpenbareRuimte']['href']);
        $nummeraanduiding['openbareRuimte'] = $this->getOpenbareruimte($bijbehorendeOpenbareRuimte['id']);
        $adres->setStraat($nummeraanduiding['openbareRuimte']['naam']);

        // Then the gemeente
        $links['bijbehorendeWoonplaats']['href'] = $nummeraanduiding['openbareRuimte']['_links']['bijbehorendeWoonplaats']['href'];
        $bijbehorendeOpenbareRuimte = $this->analyseUri($links['bijbehorendeWoonplaats']['href']);
        $nummeraanduiding['woonplaats'] = $this->getWoonplaats($bijbehorendeOpenbareRuimte['id']);

        $links['adresseerbaarObject']['href'] = $nummeraanduiding['adres']['_links']['self']['href'];
        $links['nummeraanduiding']['href'] = $nummeraanduiding['adres']['_links']['hoofdadres']['href'];

        $adres->setLinks($links);

        $adres->setWoonplaats($nummeraanduiding['woonplaats']['naam']);
        $adres->setWoonplaatsNummer($nummeraanduiding['woonplaats']['identificatiecode']);

        $adres->setStatusNummeraanduiding($nummeraanduiding['status']);
        $adres->setStatusVerblijfsobject($nummeraanduiding['adres']['status']);
        $adres->setStatusOpenbareRuimte($nummeraanduiding['openbareRuimte']['status']);
        $adres->setStatusWoonplaats($nummeraanduiding['woonplaats']['status']);

        $this->manager->persist($adres);
        $adres->setId($nummeraanduiding['identificatiecode']);
        $this->manager->persist($adres);
//        $this->manager->flush();
//
//
//        $this->manager->getRepository('App:Adres')->findBy(['id'=>$nummeraanduiding['identificatiecode']]);


        return $adres;
    }

    public function getAdresOnHuisnummerPostcode($huisnummer, $postcode)
    {
        // Lets start with th getting of nummer aanduidingen
        $now = new \Datetime();
        $query = ['huisnummer'=>$huisnummer, 'postcode'=>$postcode, 'geldigOp'=>$now->format('Y-m-d')];
        $nummeraanduidingen = $this->getNummeraanduidingen($query);

        // Lets setup an responce
        $responces = [];
        // Then we need to enrich that
        foreach ($nummeraanduidingen['nummeraanduidingen'] as $nummeraanduiding) {
            $responces[] = $this->getObject($nummeraanduiding);
        }

        return $responces;
    }

    public function getAdresOnBagId($bagId)
    {
        $nummeraanduiding = $this->getNummeraanduiding($bagId);

        $response = $this->getObject($nummeraanduiding);

        return $response;
    }
}
