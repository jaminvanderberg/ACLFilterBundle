<?php

/*
 * Copyright 2013 Radoslaw Kamil Ejsmont <radoslaw@ejsmont.net>
 *
 * Original code by mailaneel is available at
 * https://gist.github.com/mailaneel/1363377
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace jaminv\ACLFilterBundle\Bridge\Doctrine;

use JMS\DiExtraBundle\Annotation as DI;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\Common\Persistence\Mapping\ClassMetadata;
use Symfony\Component\Security\Acl\Permission\MaskBuilder;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

use jaminv\ACLFilterBundle\Bridge\Doctrine\AclWalker;

/**
 * Doctrine filter that applies ACL to fetched of entities
 *
 * @link https://gist.github.com/mailaneel/1363377 Original code on gist
 *
 * Updated 1/19/2017 - jaminvanderberg - Symfony 3 update:
 *   `security.context` replaced with `security.token_storage`
 *   Added a fix to handle erroneous role hierarchy
 *
 * @author Radoslaw Kamil Ejsmont <radoslaw@ejsmont.net>
 * @author mailaneel
 *
 * @DI\Service("vib.security.filter.acl")
 */
class AclFilter
{
    /**
     * Construct AclFilter
     *
     * @DI\InjectParams({
     *     "doctrine" = @DI\Inject("doctrine"),
     *     "tokenStorage" = @DI\Inject("security.token_storage"),
     *     "aclWalker" = @DI\Inject("%jaminv.aclfilter.acl_walker%"),
     *     "roleHierarchy" = @DI\Inject("%security.role_hierarchy.roles%")
     * }
     *
     * @param Doctrine\Common\Persistence\ManagerRegistry              $doctrine
     * @param Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage $tokenStorage
     * @param string                                                   $aclWalker
     * @param array                                                    $roleHierarchy
     */
    public function __construct(ManagerRegistry $doctrine,
            TokenStorage $tokenStorage, $aclWalker, $roleHierarchy)
    {
        $this->em = $doctrine->getManager();
        $this->tokenStorage = $tokenStorage;
        $this->aclConnection = $doctrine->getConnection();
        $this->aclWalker = $aclWalker;
        $this->roleHierarchy = $roleHierarchy;
    }

    /**
     * Apply ACL filter
     *
     * @param Doctrine\ORM\QueryBuilder | Doctrine\ORM\Query $query
     * @param array                                          $permissions
     * @param  string |
     *         Symfony\Component\Security\Core\User\UserInterface $identity
     * @param  string             $alias
     * @return Doctrine\ORM\Query
     */
    public function apply($query, array $permissions = array("VIEW"), $identity = null, $alias = null)
    {
        if (null === $identity) {
            $token = $this->tokenStorage->getToken();
            $identity = $token->getUser();
        }

        if ($query instanceof QueryBuilder) {
            $query = $this->cloneQuery($query->getQuery());
        } elseif ($query instanceof Query) {
            $query = $this->cloneQuery($query);
        } else {
            throw new \Exception();
        }

        $maskBuilder = new MaskBuilder();
        foreach ($permissions as $permission) {
            $mask = constant(get_class($maskBuilder) . '::MASK_' . strtoupper($permission));
            $maskBuilder->add($mask);
        }

        $entity = ($this->getEntityFromAlias($query, $alias));
        $metadata = $entity['metadata'];
        $alias = $entity['alias'];
        $table = $metadata->getQuotedTableName($this->em->getConnection()->getDatabasePlatform());
        $aclQuery = $this->getExtraQuery(
                $this->getClasses($metadata),
                $this->getIdentifiers($identity),
                $maskBuilder->get()
        );

        $hintAclMetadata =
            (false !== $query->getHint('acl.metadata')) ? $query->getHint('acl.metadata') : array();
        $hintAclMetadata[] = array('query' => $aclQuery, 'table' => $table, 'alias' => $alias, 'tableId' => $metadata->getSingleIdentifierColumnName());

        $query->setHint('acl.metadata', $hintAclMetadata);
        $query->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER,$this->aclWalker);

        return $query;
    }

    /**
     * Get ACL filter SQL
     *
     * @param  array   $classes
     * @param  array   $identifiers
     * @param  integer $mask
     * @return string
     */
    private function getExtraQuery($classes, $identifiers, $mask)
    {
        $database = $this->aclConnection->getDatabase();
        $inClasses = implode(",", $classes);
        $inIdentifiers = implode(",", $identifiers);

        if ($this->aclConnection->getDatabasePlatform() instanceof SqlitePlatform) {
            $database = 'main';
            $inClasses = str_replace('\\\\', '\\', $inClasses);
            $inIdentifiers = str_replace('\\\\', '\\', $inIdentifiers);
        }

        $query = <<<SELECTQUERY
SELECT DISTINCT o.object_identifier as id FROM {$database}.acl_object_identities as o
    INNER JOIN {$database}.acl_classes c ON c.id = o.class_id
    LEFT JOIN {$database}.acl_entries e ON (
        e.class_id = o.class_id AND (e.object_identity_id = o.id OR {$this->aclConnection->getDatabasePlatform()->getIsNullExpression('e.object_identity_id')})
    )
    LEFT JOIN {$database}.acl_security_identities s ON (
        s.id = e.security_identity_id
    )
    WHERE c.class_type IN ({$inClasses})
        AND s.identifier IN ({$inIdentifiers})
        AND e.mask >= {$mask}
SELECTQUERY;

        return $query;
    }

    /**
     * Resolve DQL alias into class metadata
     *
     * @param  Doctrine\ORM\Query $query
     * @param  string             $alias
     * @return array              | null
     */
    protected function getEntityFromAlias($query, $alias = null)
    {
        $em = $query->getEntityManager();
        $ast = $query->getAST();
        $fromClause = $ast->fromClause;
        foreach ($fromClause->identificationVariableDeclarations as $root) {
            $className = $root->rangeVariableDeclaration->abstractSchemaName;
            $classAlias = $root->rangeVariableDeclaration->aliasIdentificationVariable;
            if (($classAlias == $alias)||(null === $alias)) {

                return array('alias' => $classAlias,
                             'metadata' => $em->getClassMetadata($className));
            } else {
                foreach ($root->joins as $join) {
                    $joinAlias = $join->joinAssociationDeclaration->aliasIdentificationVariable;
                    $joinField = $join->joinAssociationDeclaration->joinAssociationPathExpression->associationField;
                    $joinParent = $join->joinAssociationDeclaration->joinAssociationPathExpression->identificationVariable;
                    if ($joinAlias == $alias) {
                        if ($joinParent != $classAlias) {
                            $data = $this->getEntityFromAlias($query, $joinParent);
                            $metadata = $data['metadata'];
                        } else {
                            $metadata = $em->getClassMetadata($className);
                        }
                        $joinName = $metadata->associationMappings[$joinField]['targetEntity'];

                        return array('alias' => $joinAlias,
                                     'metadata' => $em->getClassMetadata($joinName));
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get ACL compatible classes for specified class metadata
     *
     * @param  Doctrine\Common\Persistence\Mapping $metadata
     * @return array
     */
    protected function getClasses(ClassMetadata $metadata)
    {
        $classes = array();
        foreach ($metadata->subClasses as $subClass) {
            $classes[] = '"' . str_replace('\\', '\\\\', $subClass) . '"';
        }
        $classes[] = '"' . str_replace('\\', '\\\\', $metadata->name) . '"';

        return $classes;
    }

    /**
     * Get security identifiers associated with specified identity
     *
     * @param  Symfony\Component\Security\Core\User\UserInterface | string $identity
     * @return array
     */
    protected function getIdentifiers($identity)
    {
        $userClass = array();
        if ($identity instanceof UserInterface) {
            $roles = $identity->getRoles();
            $userClass[] = '"' . str_replace('\\', '\\\\', \Doctrine\Common\Util\ClassUtils::getClass($identity)) . '-' . $identity->getUserName() . '"';
        } elseif (is_string($identity)) {
            $roles = array($identity);
        } else {
            return array();
        }
        $resolvedRoles = array();
        foreach ($roles as $role) {
            $resolvedRoles[] = '"' . $role . '"';
            $resolvedRoles = array_merge($resolvedRoles, $this->resolveRoles($role));
        }
        $identifiers = array_merge($userClass,array_unique($resolvedRoles));

        return $identifiers;
    }

    /**
     * Clone query
     *
     * @param  Doctrine\ORM\AbstractQuery $query
     * @return Doctrine\ORM\AbstractQuery
     */
    protected function cloneQuery(AbstractQuery $query)
    {
        $aclAppliedQuery = clone $query;
        $params = $query->getParameters();
        $aclAppliedQuery->setParameters($params);

        return $aclAppliedQuery;
    }

    /**
     * Get parent roles of the specified role
     *
     * 1/19/2017 - jaminvanderberg - Added a fix to handle circular (wrong) role hierachies without crashing.
     *
     * @param  string $role
     * @return array
     */
    protected function resolveRoles($role)
    {
        $hierarchy = $this->roleHierarchy;
        $roles = array();
        if (array_key_exists($role, $hierarchy)) {
            foreach ($hierarchy[$role] as $parent_role) {
                $roles[] = '"' . $parent_role . '"';
                if ($parent_role !== $role) {
                    $roles = array_merge($roles,$this->resolveRoles($parent_role));
                }
            }
        }

        return $roles;
    }
}
