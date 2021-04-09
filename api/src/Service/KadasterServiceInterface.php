<?php


namespace App\Service;


use App\Entity\Adres;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface as CacheInterface;

interface KadasterServiceInterface
{

    public function __construct(ParameterBagInterface $params, CacheInterface $cache, EntityManagerInterface $entityManager);
    /**
     * @param string $bagId
     * @return Adres
     */
    public function getAdresOnBagId(string $bagId): Adres;

    /**
     * @param $huisnummer
     * @param $postcode
     * @return Adres
     */
    public function getAdresOnHuisnummerPostcode($huisnummer, $postcode): array;
}
