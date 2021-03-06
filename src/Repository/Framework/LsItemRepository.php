<?php

namespace App\Repository\Framework;

use App\Entity\Framework\LsAssociation;
use App\Entity\Framework\LsDoc;
use App\Entity\Framework\LsDocAttribute;
use App\Entity\Framework\LsItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\RegistryInterface;

/**
 * LsItemRepository
 *
 * @method null|LsItem findOneByIdentifier(string $identifier)
 */
class LsItemRepository extends ServiceEntityRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, LsItem::class);
    }

    /**
     * @param LsDoc $lsDoc
     *
     * @return array
     */
    public function findAllForDoc(LsDoc $lsDoc)
    {
        return $this->findAllForDocQueryBuilder($lsDoc)->getQuery()->getResult();
    }

    public function findAllForDocWithAssociations(LsDoc $lsDoc)
    {
        $qry = $this->findAllForDocQueryBuilder($lsDoc);
        $qry->leftJoin('fa.destinationLsItem', 'fad')
            ->leftJoin('fa.originLsItem', 'fao')
            ->leftJoin('ia.originLsItem', 'iao')
            ->leftJoin('ia.destinationLsItem', 'iad')
            ;

        return $qry->getQuery()->getResult();
    }

    /**
     * @param string $key
     *
     * @return array
     */
    public function findAllByIdentifierOrHumanCodingSchemeByValue($key)
    {
        $qry = $this->createQueryBuilder('i');
        $qry->select('i')
            ->where($qry->expr()->orX(
                $qry->expr()->eq('i.humanCodingScheme', ':humanCodingScheme'),
                $qry->expr()->eq('i.identifier', ':identifier')
            ))
            ->setParameter('humanCodingScheme', $key)
            ->setParameter('identifier', $key)
            ;
        return $qry->getQuery()->getResult();
    }

    /**
     * @param string $lsDocId
     * @param string $key
     *
     * @return array
     */
    public function findByAllIdentifierOrHumanCodingSchemeByLsDoc($lsDocId, $key)
    {
        $qry = $this->createQueryBuilder('i');
        $qry->select('i')
            ->where($qry->expr()->orX(
                $qry->expr()->eq('i.humanCodingScheme', ':humanCodingScheme'),
                $qry->expr()->eq('i.identifier', ':identifier')
            ), 'i.lsDoc = :lsDocId')
            ->setParameter('humanCodingScheme', $key)
            ->setParameter('identifier', $key)
            ->setParameter('lsDocId', $lsDocId)
            ;
        return $qry->getQuery()->getResult();
    }

    public function findAllForDocQueryBuilder(LsDoc $lsDoc): QueryBuilder
    {
        $qry = $this->createQueryBuilder('i')
            ->leftJoin('i.associations', 'fa')
            ->leftJoin('i.inverseAssociations', 'ia')
            ->leftJoin('i.itemType', 'item_type')
            ->where('i.lsDoc = :lsDoc')
            ->orderBy('i.rank', 'ASC')
            ->addOrderBy('i.listEnumInSource', 'ASC')
            ->addOrderBy('i.humanCodingScheme', 'ASC')
            ->setParameter('lsDoc', $lsDoc->getId())
            ;

        return $qry;
    }

    /**
     * @return LsAssociation[]
     */
    public function findChildAssociations(LsItem $parent, LsItem $child): array
    {
        $associations = [];
        foreach ($child->getAssociations() as $association) {
            if ($association->getType() === LsAssociation::CHILD_OF
                && null !== $association->getDestinationLsItem()
                && $association->getDestinationLsItem()->getId() === $parent->getId()) {
                $associations[] = $association;
            }
        }

        return $associations;
    }

    public function removeAssociation(LsAssociation $association): void
    {
        $this->_em->getRepository(LsAssociation::class)->removeAssociation($association);
    }

    public function removeChild(LsItem $parent, LsItem $child): void
    {
        $associations = $this->findChildAssociations($parent, $child);
        foreach ($associations as $association) {
            $this->removeAssociation($association);
        }
    }

    public function removeItemAndChildren(LsItem $lsItem): bool
    {
        $children = $lsItem->getChildren();
        foreach ($children as $child) {
            $this->removeItemAndChildren($child);
        }

        return $this->removeItem($lsItem);
    }

    public function removeItem(LsItem $lsItem): bool
    {
        $hasChildren = $lsItem->getChildren();
        if ($hasChildren->isEmpty()) {
            $this->_em->getRepository(LsAssociation::class)->removeAllAssociations($lsItem);
            $this->_em->remove($lsItem);

            return true;
        }

        return false;
    }

    public function findExactMatches(String $identifier): array
    {
        $assocRepo = $this->_em->getRepository(LsAssociation::class);

        $item = $this->findOneByIdentifier($identifier);
        if (null === $item) {
            return [];
        }

        $matched= [$item->getId() => $item];
        $matchedCount = 0;

        while (count($matched) !== $matchedCount) {
            $matchedCount = count($matched);

            $fromCriteria = new Criteria();
            $fromCriteria->where(Criteria::expr()->in('originLsItem', array_keys($matched)));
            $fromCriteria->andWhere(Criteria::expr()->eq('type', LsAssociation::EXACT_MATCH_OF));
            $results = $assocRepo->matching($fromCriteria);
            foreach ($results as $assoc) {
                /** @var LsAssociation $assoc */
                $item = $assoc->getDestinationLsItem();
                if (null !== $item) {
                    $matched[$item->getId()] = $item;
                }
            }

            $toCriteria = new Criteria();
            $toCriteria->where(Criteria::expr()->in('destinationLsItem', array_keys($matched)));
            $toCriteria->andWhere(Criteria::expr()->eq('type', LsAssociation::EXACT_MATCH_OF));
            $results = $assocRepo->matching($toCriteria);
            foreach ($results as $assoc) {
                /** @var LsAssociation $assoc */
                $item = $assoc->getOriginLsItem();
                if (null !== $item) {
                    $matched[$item->getId()] = $item;
                }
            }
        }

        return $matched;
    }

    public function createGradeSelectListQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('i')
            ->join('i.associations', 'a', 'WITH', 'a.type = :isChild')
            ->leftJoin('a.destinationLsItem', 'dest')
            ->leftJoin('dest.associations', 'desta', 'WITH', 'desta.type = :isChild')
            ->leftJoin('desta.destinationLsItem', 'dest2')
            ->join('a.lsDoc', 'd')
            ->join('d.attributes', 'attributes')
            ->andWhere('attributes.attribute = :isGradeLevels')
            ->andWhere("attributes.value = 'yes'")
            ->orderBy('i.rank', 'ASC')
            ->addOrderBy('i.listEnumInSource', 'ASC')
            ->addOrderBy('i.humanCodingScheme', 'ASC')
            ->setParameters([
                'isChild' => LsAssociation::CHILD_OF,
                'isGradeLevels' => LsDocAttribute::IS_GRADE_LEVELS
            ])
            ;
    }
}
