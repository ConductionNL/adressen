<?php

namespace App\Service;

use App\Entity\Adres;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface as CacheInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

interface KadasterServiceInterface
{
    public function __construct(ParameterBagInterface $params, CacheInterface $cache, EntityManagerInterface $entityManager);

    /**
     * @param string $bagId
     *
     * @return Adres
     */
    public function getAdresOnBagId(string $bagId): Adres;

    /**
     * @param $huisnummer
     * @param $postcode
     *
     * @return Adres
     */
    public function getAdresOnHuisnummerPostcode($huisnummer, $postcode): array;

    /**
     * @param string $street
     * @param string $houseNumber
     * @param string|null $houseNumberSuffix
     * @param string $locality
     * @return array
     */
    public function getAdresOnStraatnaamHuisnummerPlaatsnaam(string $street, string $houseNumber, ?string $houseNumberSuffix = null, string $locality): array;
}
