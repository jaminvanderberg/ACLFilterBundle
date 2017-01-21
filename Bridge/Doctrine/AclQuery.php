<?php

/*
 * Copyright 2017 Jamin VanderBerg <jaminvanderberg@yahoo.com>
 *
 * Code added 2017 and is not part of the original VIB/SecurityBundle.
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

use Doctrine\Common\Persistence\ManagerRegistry;

/**
 * Doctrine query that list security identities that have access to an object.
 *
 * This is useful for displaying to users with GRANT permissions, so that
 * they can control who has access to their objects.
 *
 * It is recommended that you check that the user has GRANT permissions on
 * the object before returning the results of this query to them.  This class
 * does not explicitly do that.
 *
 * This class does not currently traverse role hierachies or object ancestors.
 * It returns only direct object identity <-> security identity relationships.
 * There are currently no plans to add this functionality, as the current use
 * case (displaying permissions to the user for editing) only applies to direct
 * relationships.
 *
 * @author Jamin VanderBerg <jaminvanderberg@yahoo.com>
 */
class AclQuery
{
    /**
     * Construct AclQuery
     *
     * @param Doctrine\Common\Persistence\ManagerRegistry              $doctrine
     */
    public function __construct(ManagerRegistry $doctrine)
    {
        $this->em = $doctrine->getManager();
    }

    /**
     * Query the ACL for a specific object identity
     *
     * The query results will have 3 fields:
     * * security_identifier: (string) The security identifier, which will usually be "AppBundle\\Entity\\User-<username>" or "ROLE_<role>"
     * * is_username: (boolean) Will be 1 if the security identifier is a user identified by its username.  Will be 0 otherwise, which should signify that it is a ROLE.
     * * mask: (int) The permission mask for that user. Compare against MaskBuilder masks, if this number is greater than the MaskBuilder mask, the user has those permissions.
     *
     * @param string $class_type The name of the ACL class. Usually this will be something like "AppBundle\\Entity\\<entity_name>"
     * @param int|string $id The unique identifier (primary key) for the object. Usually this will be an integer representing the `id` field of the row.
     * @param string $field (default: NULL) Used to specify field-level permisisons. If omitted, object-level permissions are returned.
     * @return array The results of the query. See above.
     */
    public function queryAcl($class_type, $id, $field = NULL)
    {

        $conn = $this->em->getConnection();

        $fieldquery = ($field === NULL ? 'e.field IS ?' : 'e.field = ?');
        $query = <<<SELECTQUERY
SELECT s.identifier AS security_identifier, s.username AS is_username, e.mask
    FROM acl_entries AS e
        LEFT JOIN acl_classes AS c ON c.id = e.class_id
        LEFT JOIN acl_object_identities AS o ON o.id = e.object_identity_id
        LEFT JOIN acl_security_identities AS s ON s.id = e.security_identity_id
        WHERE c.class_type = ?
            AND o.object_identifier = ?
            AND $fieldquery
SELECTQUERY;

        $sql = $conn->prepare($query);
        $sql->bindValue(1, $class_type);
        $sql->bindValue(2, $id);
        $sql->bindValue(3, $field);
        $sql->execute();
        return $sql->fetchAll();
    }
}
