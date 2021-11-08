<?php

namespace App\Service;

use App\Entity\Adres;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Serializer\Encoder\XmlEncoder;

class LoadExtractService
{
    private EntityManagerInterface $entityManager;
    private SymfonyStyle $io;
    private XmlEncoder $xmlEncoder;

    public function __construct(EntityManagerInterface $entityManager, SymfonyStyle $io)
    {
        $this->entityManager = $entityManager;
        $this->xmlEncoder = new XmlEncoder(['xml_root_node_name' => 'soap:Envelope']);
        $this->io = $io;


        ini_set('memory_limit', '2G');
    }

    public function loadXmlFile(string $filename): array
    {
        $file = file_get_contents($filename);
        return $this->xmlEncoder->decode($file, 'xml');
    }

    function convert($size)
    {
        $unit=array('b','kb','mb','gb','tb','pb');
        return @round($size/pow(1024,($i=floor(log($size,1024)))),2).' '.$unit[$i];
    }

    public function loadNummerObject(array $nummeraanduidingArray): void
    {
        if(!($nummeraanduiding = $this->entityManager->getRepository("App:Adres")->findOneBy(['id' => $nummeraanduidingArray['Objecten:identificatie']['#']]))){
            $nummeraanduiding = new Adres();
        } else {
//            $this->io->error('MUTATIE!');
        }

        isset($nummeraanduidingArray['Objecten:huisnummer']) ? $nummeraanduiding->setHuisnummer($nummeraanduidingArray['Objecten:huisnummer']) : null;
        isset($nummeraanduidingArray['Objecten:huisletter']) ? $huisnummerToevoeging = $nummeraanduidingArray['Objecten:huisletter'] : null;
        isset($nummeraanduidingArray['Objecten:huisnummertoevoeging']) ? (isset($huisnummerToevoeging) ? $huisnummerToevoeging .= " {$nummeraanduidingArray['Objecten:huisnummertoevoeging']}" : $huisnummerToevoeging = $nummeraanduidingArray['Objecten:huisnummertoevoeging']) : null;
        isset($nummeraanduidingArray['Objecten:postcode']) ? $nummeraanduiding->setPostcode($nummeraanduidingArray['Objecten:postcode']) : null;
        isset($nummeraanduidingArray['Objecten:status']) ? $nummeraanduiding->setStatusNummeraanduiding($nummeraanduidingArray['Objecten:status']) : null;
        isset($huisnummerToevoeging) ? $nummeraanduiding->setHuisnummertoevoeging($huisnummerToevoeging) : null;

        $this->entityManager->persist($nummeraanduiding);
        $nummeraanduiding->setId($nummeraanduidingArray['Objecten:identificatie']['#']);
        $this->entityManager->persist($nummeraanduiding);
        $this->entityManager->flush();
//        $this->io->text("Loaded nummeraanduiding {$nummeraanduiding->getId()}");
    }

    public function loadNummerObjectenPerFile(string $filename): void
    {
        $data = $this->loadXmlFile($filename);
        $this->io->text("Used {$this->convert(memory_get_usage())} of memory");
        $this->io->createProgressBar(count($data['sl:standBestand']['sl:stand']));
        $this->io->progressStart(count($data['sl:standBestand']['sl:stand']));
        foreach($data['sl:standBestand']['sl:stand'] as $key=>$bagObject) {
//            $this->io->text($key);
            $this->loadNummerObject($bagObject["sl-bag-extract:bagObject"]['Objecten:Nummeraanduiding']);
            $this->io->progressAdvance();
        }
        $data = null;
        $this->io->progressFinish();
        $this->entityManager->clear();
    }

    public function loadNummerObjecten()
    {
        $filenames = array_diff(scandir(dirname(__FILE__, 3).'/var/import/nummeraanduidingen'), array('..', '.'));
        foreach($filenames as $filename){
            $this->io->section("Loading $filename");
            $this->loadNummerObjectenPerFile(dirname(__FILE__, 3).'/var/import/nummeraanduidingen/'.$filename);
        }
    }
}
