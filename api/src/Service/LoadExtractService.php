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

    private array $openbareRuimten;
    private array $woonplaatsen;

    public function __construct(EntityManagerInterface $entityManager, SymfonyStyle $io)
    {
        $this->entityManager = $entityManager;
        $this->xmlEncoder = new XmlEncoder(['xml_root_node_name' => 'soap:Envelope']);
        $this->io = $io;
        $this->openbareRuimten = [];
        $this->woonplaatsen = [];

        ini_set('memory_limit', '512M');
    }

    public function getDayMutations(): void
    {

    }

    public function loadMutationsPerFile(string $filename): void
    {
        $data = $this->loadXmlFile($filename);
        $this->io->text("Used {$this->convert(memory_get_usage())} of memory");
        if(isset($data['ml:mutatieBericht']['ml:mutatieGroep'])){
            var_dump(array_keys($data['ml:mutatieBericht']));
            foreach($data['ml:mutatieBericht']['ml:mutatieGroep'] as $mutatie){
                var_dump($mutatie);
                die;
            }
        }
//        foreach()
    }

    public function processMutations(): void
    {
        $filenames = array_diff(scandir(dirname(__FILE__, 3).'/var/import/mutaties'), array('..', '.'));
        foreach($filenames as $filename){
            $this->io->section("Loading $filename");
            $this->loadMutationsPerFile(dirname(__FILE__, 3).'/var/import/mutaties/'.$filename);
        }
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
        var_dump($nummeraanduidingArray);
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
        if(isset($nummeraanduidingArray['Objecten:ligtAan']["Objecten-ref:OpenbareRuimteRef"]['#']) && $openbareRuimte = $this->openbareRuimten[$nummeraanduidingArray['Objecten:ligtAan']["Objecten-ref:OpenbareRuimteRef"]['#']]){
//            var_dump($openbareRuimte);
            $nummeraanduiding->setStatusOpenbareRuimte($openbareRuimte['status']);
            $nummeraanduiding->setStraat($openbareRuimte['naam']);
            $nummeraanduiding->setWoonplaatsNummer($openbareRuimte['woonplaats']);;
        }
        if($nummeraanduiding->getWoonplaatsNummer() && isset($this->woonplaatsen[$nummeraanduiding->getWoonplaatsNummer()]) && $woonplaats = $this->woonplaatsen[$nummeraanduiding->getWoonplaatsNummer()]){
//            var_dump($woonplaats);
            $nummeraanduiding->setWoonplaats($woonplaats['naam']);
            $nummeraanduiding->setStatusWoonplaats($woonplaats['status']);
        }

        $this->entityManager->persist($nummeraanduiding);
        $nummeraanduiding->setId($nummeraanduidingArray['Objecten:identificatie']['#']);
        $this->entityManager->persist($nummeraanduiding);
        $this->entityManager->flush();
        $this->entityManager->clear();
        $nummeraanduiding = null;
        $nummeraanduidingArray = [];
        gc_collect_cycles();
//        die;
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

        gc_collect_cycles();
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

    public function loadVerblijfsObject(array $verblijfsobjectArray): void
    {
        if(!($nummeraanduiding = $this->entityManager->getRepository("App:Adres")->findOneBy(['id' => $verblijfsobjectArray['Objecten:heeftAlsHoofdadres']['Objecten-ref:NummeraanduidingRef']['#']]))){
            $nummeraanduiding = new Adres();
        } elseif ($nummeraanduiding->getType()){
            // Mutatie
        }

        $nummeraanduiding->setOppervlakte($verblijfsobjectArray['Objecten:oppervlakte'] ?? null);
        $nummeraanduiding->setStatusVerblijfsobject($verblijfsobjectArray['Objecten:status'] ?? null);
        $typeRef = explode('.', $verblijfsobjectArray['Objecten:maaktDeelUitVan'][array_key_first($verblijfsobjectArray['Objecten:maaktDeelUitVan'])]['@domein']);
        $nummeraanduiding->setType(strtolower(end($typeRef)));

        $this->entityManager->persist($nummeraanduiding);
        $this->entityManager->flush();
        $this->entityManager->clear();
        $verblijfsobjectArray = [];
        $nummeraanduiding = null;
        gc_collect_cycles();
//        $this->io->text("Loaded nummeraanduiding {$nummeraanduiding->getId()}");
    }

    public function loadVerblijfsObjectenPerFile(string $filename): void
    {
        $data = $this->loadXmlFile($filename);
        $this->io->text("Used {$this->convert(memory_get_usage())} of memory");
        $this->io->createProgressBar(count($data['sl:standBestand']['sl:stand']));
        $this->io->progressStart(count($data['sl:standBestand']['sl:stand']));
        foreach($data['sl:standBestand']['sl:stand'] as $key=>$bagObject) {
//            $this->io->text($key);
            $this->loadVerblijfsObject($bagObject["sl-bag-extract:bagObject"]['Objecten:Verblijfsobject']);
            $this->io->progressAdvance();
        }
        $data = null;
        $this->io->progressFinish();
        $this->entityManager->clear();
        gc_collect_cycles();
    }

    public function loadVerblijfsObjecten(): void
    {
        $filenames = array_diff(scandir(dirname(__FILE__, 3).'/var/import/verblijfsobjecten'), array('..', '.'));
        foreach($filenames as $filename){
            $this->io->section("Loading $filename");
            $this->loadVerblijfsObjectenPerFile(dirname(__FILE__, 3).'/var/import/verblijfsobjecten/'.$filename);
        }
    }

    public function loadOpenbareRuimte(array $object): void
    {
        $openbareRuimte = [
            'woonplaats'    => $object['Objecten:ligtIn']['Objecten-ref:WoonplaatsRef']['#'] ?? null,
            'naam'          => $object['Objecten:naam'] ?? null,
            'status'        => $object['Objecten:status'] ?? null,
        ];
        $this->openbareRuimten[$object['Objecten:identificatie']['#']] = $openbareRuimte;

        $object = null;
        gc_collect_cycles();
    }

    public function loadOpenbareRuimtesPerFile(string $filename): void
    {
        $data = $this->loadXmlFile($filename);
        $this->io->text("Used {$this->convert(memory_get_usage())} of memory");
        $this->io->createProgressBar(count($data['sl:standBestand']['sl:stand']));
        $this->io->progressStart(count($data['sl:standBestand']['sl:stand']));
        foreach($data['sl:standBestand']['sl:stand'] as $key=>$bagObject) {
//            $this->io->text($key);
            $this->loadOpenbareRuimte($bagObject["sl-bag-extract:bagObject"]['Objecten:OpenbareRuimte']);
            $this->io->progressAdvance();
        }
    }

    public function loadOpenbareRuimtes(): void
    {
        $filenames = array_diff(scandir(dirname(__FILE__, 3).'/var/import/openbareruimtes'), array('..', '.'));
        foreach($filenames as $filename){
            $this->io->section("Loading $filename");
            $this->loadOpenbareRuimtesPerFile(dirname(__FILE__, 3).'/var/import/openbareruimtes/'.$filename);
        }
    }

    public function loadWoonplaats(array $object): void
    {
        $woonplaats = [
            'status'    => $object['Objecten:status'],
            'naam'      => $object['Objecten:naam'],
        ];
        $this->woonplaatsen[$object['Objecten:identificatie']['#']] = $woonplaats;

        $object = null;
        gc_collect_cycles();
    }

    public function loadWoonplaatsenPerFile(string $filename): void
    {
        $data = $this->loadXmlFile($filename);
        $this->io->text("Used {$this->convert(memory_get_usage())} of memory");
        $this->io->createProgressBar(count($data['sl:standBestand']['sl:stand']));
        $this->io->progressStart(count($data['sl:standBestand']['sl:stand']));
        foreach($data['sl:standBestand']['sl:stand'] as $key=>$bagObject) {
//            $this->io->text($key);
            $this->loadWoonplaats($bagObject["sl-bag-extract:bagObject"]['Objecten:Woonplaats']);
            $this->io->progressAdvance();
        }
    }

    public function loadWoonplaatsen(): void
    {
        $filenames = array_diff(scandir(dirname(__FILE__, 3).'/var/import/woonplaatsen'), array('..', '.'));
        foreach($filenames as $filename){
            $this->io->section("Loading $filename");
            $this->loadWoonplaatsenPerFile(dirname(__FILE__, 3).'/var/import/woonplaatsen/'.$filename);
        }
    }
}
