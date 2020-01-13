<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiProperty;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\NumericFilter;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An BAG addres.
 *
 * @category   	Entity
 *
 * @author     	Ruben van der Linde <ruben@conduction.nl>
 * @license    	EUPL 1.2 https://opensource.org/licenses/EUPL-1.2 *
 *
 * @version    	1.0
 *
 * @link   		http://www.conduction.nl
 *
 *
 * @ApiResource(
 *     collectionOperations={
 *          "get"={
 *      		"path"="/adressen",
 *              "method"="GET",
 *              "swagger_context" = {
 *              	"description" = "Gets an list of BAG [nummer aanduidingen]( https://bag.basisregistraties.overheid.nl/restful-api/-/article/basisregistraties-adressen-en-gebouwen-bag-#/paths/~1nummeraanduidingen/get) enriched with __straatnaam__,__woonplaats__ and __gemeente_nummer__ .",
 *                  "parameters" = {
 *                      {
 *                          "name" = "huisnummer",
 *                          "in" = "query",
 *                          "description" = "The number of the building",
 *                          "required" = true,
 *                          "type" : "integer"
 *                      },
 *                      {
 *                          "name" = "huisnummer_toevoeging",
 *                          "in" = "query",
 *                          "description" = "The suffix of the house number. This is used to filter a result list. Only applied when one or more matches can be found. Compared in a non-strict manner, meaning a wildcard is applied both after and before the given value when comparing for matches",
 *                          "required" = false,
 *                          "type" : "string"
 *                      },
 *                      {
 *                          "name" = "postcode",
 *                          "in" = "query",
 *                          "description" = "The zip or postcode of the address in a P6 format e.g. 1234AB (without spaces)",
 *                          "required" = true,
 *                          "type" : "string",
 *                          "format" : "P6",
 *             				"maxLength"="6",
 *             				"minLength"="6"
 *                      }
 *                  }
 *               }
 *          }
 *     },
 *     itemOperations={
 *     }
 * )
 * @ORM\Entity()
 * @ApiFilter(NumericFilter::class, properties={"test"})
 */
class Adres
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="string")
     * @Assert\Length(
     *     max = 16
     * )
     * @ApiProperty(
     * 	   identifier=true,
     *     attributes={
     *         "swagger_context"={
     *         	   "description" = "The BAG identifier of this address",
     *             "type"="string",
     *             "example"="0363200000218908"
     *         }
     *     }
     * )
     */
    private $id;

    /**
     * @param string $type The type of this address.
     *
     * @example verblijfsobject
     *
     * @Groups({"read"})
     * @Assert\Choice(
     *     {"ligplaats","standplaats","verblijfsobject","woonplaats","pand"}
     * )
     *
     * @ApiProperty(
     *     attributes={
     *         "swagger_context"={
     *         	   "description" = "The type of address",
     *             "type"="string",
     *             "enum"={"verblijfsobject", "ligplaats", "standplaats"},
     *             "example"="verblijfsobject"
     *         }
     *     }
     * )
     */
    private $type;

    /**
     * @param int $oppervlakte The surface area in square meters (in case of verblijfsobject)
     *
     * @example 22214
     *
     * @Groups({"read"})
     */
    private $oppervlakte;

    /**
     * @param int $huisnummer The house number of this address.
     *
     * @example 147
     *
     * @Groups({"read"})
     *
     * @Assert\Type("integer")
     * @Assert\Length(
     *     max = 5
     * )
     */
    private $huisnummer;

    /**
     * @param string $huisnummerToevoeging The suffix of the house number of this address.
     *
     * @example huis
     *
     * @Groups({"read"})
     * @Assert\Length(
     *     max = 4
     * )
     */
    private $huisnummerToevoeging;

    /**
     * @param string $straat The street name of this address.
     *
     * @example Nieuwezijds Voorburgwal
     *
     * @Groups({"read"})
     * @Assert\Type("string")
     * @Assert\NotBlank
     */
    private $straat;

    /**
     * @param string $postcode The zip or postalcode of this address.
     *
     * @example 1012RJ
     *
     * @Groups({"read"})
     * @Assert\Length(
     *     min = 6,
     *     max = 6
     * )
     * @Assert\NotBlank
     */
    private $postcode;

    /**
     * @param string $woonplaats The city  to witch this adres belongs.
     *
     * @example Amsterdam
     *
     * @Groups({"read"})
     * @Assert\Type("string")
     */
    private $woonplaats;

    /**
     * @param string $woonplaatsNummer The ID of the city or locality to which this address belongs.
     *
     * @example 3594
     *
     * @Groups({"read"})
     * @Assert\Type("integer")
     * @Assert\Length(
     *     max = 4
     * )
     */
    private $woonplaatsNummer;

    /**
     * @param string $gemeenteNummer The ID of the locality to witch this adres belongs.
     *
     * @example 3594
     *
     * @Groups({"read"})
     * @Assert\Type("integer")
     * @Assert\Length(
     *     max = 4
     * )
     */
    private $gemeenteNummer;

    /**
     * @param string $gemeenteRsin The RSIN of the city or locality to which this address belongs.
     *
     * @example 002220647
     *
     * @Groups({"read"})
     * @Assert\Type("integer")
     * @Assert\Length(
     *     max = 9
     * )
     */
    private $gemeenteRsin;

    /**
     * @param string $statusNummeraanduiding The last known status of this address.
     *
     * @example NaamgevingUitgegeven
     *
     * @Groups({"read"})
     * @Assert\Type("string")
     */
    private $statusNummeraanduiding;

    /**
     * @param string $status_verblijfsobject The last known status of this address.
     *
     * @example VerblijfsobjectInGebruik
     *
     * @Groups({"read"})
     * @Assert\Type("string")
     */
    private $statusVerblijfsobject;

    /**
     * @param string $status_openbare_ruimte The last known status of this address.
     *
     * @example NaamgevingUitgegeven
     *
     * @Groups({"read"})
     * @Assert\Type("string")
     */
    private $statusOpenbareRuimte;

    /**
     * @param string $status_woonplaats The last known status of this address.
     *
     * @example WoonplaatsAangewezen
     *
     * @Groups({"read"})
     * @Assert\Type("string")
     */
    private $statusWoonplaats;

    /**
     * @param array $links The Bag objects beloning to this adress.
     * @Groups({"read"})
     */
    private $links = [];

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(?string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getOppervlakte(): ?int
    {
        return $this->oppervlakte;
    }

    public function setOppervlakte(?int $oppervlakte): self
    {
        $this->oppervlakte = $oppervlakte;

        return $this;
    }

    public function getHuisnummer(): ?int
    {
        return $this->huisnummer;
    }

    public function setHuisnummer(?int $huisnummer): self
    {
        $this->huisnummer = $huisnummer;

        return $this;
    }

    public function getHuisnummerToevoeging(): ?string
    {
        return $this->huisnummerToevoeging;
    }

    public function setHuisnummerToevoeging(?string $huisnummerToevoeging): self
    {
        $this->huisnummerToevoeging = $huisnummerToevoeging;

        return $this;
    }

    public function getStraat(): ?string
    {
        return $this->straat;
    }

    public function setStraat(?string $straat): self
    {
        $this->straat = $straat;

        return $this;
    }

    public function getPostcode(): ?string
    {
        return $this->postcode;
    }

    public function setPostcode(?string $postcode): self
    {
        $this->postcode = $postcode;

        return $this;
    }

    public function getWoonplaats(): ?string
    {
        return $this->woonplaats;
    }

    public function setWoonplaats(?string $woonplaats): self
    {
        $this->woonplaats = $woonplaats;

        return $this;
    }

    public function getWoonplaatsNummer(): ?int
    {
        return $this->woonplaatsNummer;
    }

    public function setWoonplaatsNummer(?int $woonplaatsNummer): self
    {
        $this->woonplaatsNummer = $woonplaatsNummer;

        return $this;
    }

    public function getGemeenteNummer(): ?int
    {
        return $this->gemeenteNummer;
    }

    public function setGemeenteNummer(?int $gemeenteNummer): self
    {
        $this->gemeenteNummer = $gemeenteNummer;

        return $this;
    }

    public function getGemeenteRsin(): ?string
    {
        return $this->gemeenteRsin;
    }

    public function setGemeenteRsin(?string $gemeenteRsin): self
    {
        $this->gemeenteRsin = $gemeenteRsin;

        return $this;
    }

    public function getStatusNummeraanduiding(): ?string
    {
        return $this->statusNummeraanduiding;
    }

    public function setStatusNummeraanduiding(?string $statusNummeraanduiding): self
    {
        $this->statusNummeraanduiding = $statusNummeraanduiding;

        return $this;
    }

    public function getStatusVerblijfsobject(): ?string
    {
        return $this->statusVerblijfsobject;
    }

    public function setStatusVerblijfsobject(?string $statusVerblijfsobject): self
    {
        $this->statusVerblijfsobject = $statusVerblijfsobject;

        return $this;
    }

    public function getStatusOpenbareRuimte(): ?string
    {
        return $this->statusOpenbareRuimte;
    }

    public function setStatusOpenbareRuimte(?string $statusOpenbareRuimte): self
    {
        $this->statusOpenbareRuimte = $statusOpenbareRuimte;

        return $this;
    }

    public function getStatusWoonplaats(): ?string
    {
        return $this->statusWoonplaats;
    }

    public function setStatusWoonplaats(?string $statusWoonplaats): self
    {
        $this->statusWoonplaats = $statusWoonplaats;

        return $this;
    }

    public function getLinks(): ?array
    {
        return $this->links;
    }

    public function setLinks(?array $_links): self
    {
        $this->links = $_links;

        return $this;
    }
}
