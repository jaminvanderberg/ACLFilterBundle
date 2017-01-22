# ACL Filter bridge for Doctrine QueryBuilder

This bundle lets your set up Doctrine queries that are joined against the ACL.
This helps close a gap in the Symfony's current ACL implementation. While
Symfony ACL allows you to efficiently check permissions for a specific object,
it has no efficient way to build list queries that only return objects for
which a user has been granted permission.

Under Symfony's ACL, the only way to list resources in this way is too loop
through them and test each one individually.  Pre-caching can improve the
performance somewhat, but if there are thousands of rows or more with only a
few valid results, this is far from ideal.  This system also doesn't lend
itself well to limit/offset queries.

This bundle gets around this by modifying queries built with QueryBuilder to
join the appropriate tables so that ACL permissions are checked during query
execution.  The result is a very efficient query that only returns the results
that you want.  Special query options, such as count, limit, or offset, will
all work normally.

The catch is that this bundle has to query the database directly, so
there may be compatibility issues or issues with future revisions of Doctrine.
It should be compatible with most databases, however, and I was able to
implement code from 4 years ago with very few changes to Symfony code only.
No code changes were made to the Doctrine-specific code, so this should
continue to work with Doctrine versions into the near future at least.

* Cloned from <a href="https://github.com/rejsmont/LabDB">rejsmont/LabDB</a>.
* Extracted just the src/VIB/SecurityBundle package
* Removed and changed some files that weren't relevant to the ACL filter system.
* Renamed to ACLFilterBundle, to reflect singular purpose.
* Updated to work with Symfony 3
* Added documentation
* Added AclQuery service

### AclQuery

I also noticed that Symfony's ACL bundle doesn't have a way to query the ACL
to get a list of users/roles that have access to an object.  I needed this so
that I could give users an interface to edit permissions on their objects.
So I added the AclQuery service to this bundle to handle this functionality.

Like the AclFilter service, AclQuery relies on direct SQL queries to the
database, so there may be some compatibility issues with some databases.
The only alternative would be to set up Doctrine entities for each of
the ACL database tables, which is normally undesirable.

## Installation

### Composer Install

    composer require jaminv/aclfilter-bundle

or edit composer.json:

    # /composer.json
    "require": {
        ...
        "jaminv/aclfilter-bundle": "dev-master"
    },

### Register Bundle

    # /app/AppKernel.php
    class AppKernel extends Kernel
    {
        public function registerBundles()
        {
            $bundles = [
                ...
                new jaminv\ACLFilterBundle\jaminvACLFilterBundle(),

                new AppBundle\AppBundle(),
            ];
            ...

## Usage

### Basic Usage

Set up a basic query:

    $em = $this->getDoctrine->getManager();
    $builder = em->getRepository('AppBundle:SomeTable')->getQueryBuilder('a');
    $builder->select('a')
        ->where("a.somefield = 'value'");

Apply the AclFilter to the query:

    $aclfilter = $this->get('jaminv.aclfilter');
    $query = $aclfilter->apply($builder->getQuery(), array('EDIT'), $this->getUser(), 'a');
    $result = $query->getResult();

The AclFilter will modify the query so that it only returns results for objects
for which the current user has EDIT permissions.

### AclQuery Usage

Using AclQuery to list users/roles that have access to an object can be done
in a single line:

    $result = $this->get('jaminv.aclquery')->queryAcl("AppBundle\\Entity\\SomeTable", $id);

The result is an array that might look something like this:

    [{"security_identifier":"ROLE_ADMIN","is_username":"0","mask":"32"},
    {"security_identifier":"AppBundle\\Entity\\User-username","is_username":"1","mask":"128"}]

Each entry in the array has 3 fields:

* security_identifier: (string) The security identifier, which will usually be "AppBundle\\Entity\\User-<username>" or "ROLE_<role>"
* is_username: (boolean) Will be 1 if the security identifier is a user identified by its username.  Will be 0 otherwise, which should signify that it is a ROLE.
* mask: (int) The permission mask for that user. Compare against MaskBuilder masks; if this number is greater than the MaskBuilder mask, the user has those permissions.

In the above example, the mask values indicate that the user "username" has been
granted OWNER permissions, while the role ROLE_ADMIN has been granted MASTER
permissions.

`AclQuery::queryAcl` also accepts a third, optional, parameter which is a field
name.  This can be used to perform the same operation for field-level permissions.
Note that if you do not include this parameter, the query will only return
object-level permissions and will not return field-level permissions.  Likewise,
using this parameter will only return field-level permissions.

It is recommended that you check that the user has GRANT permissions on
the object before returning the results of this query to them.  The AclQuery
service does not explicitly do that.

The AclQuery service does not currently traverse role hierachies or object ancestors.
It returns only direct object identity <-> security identity relationships.
There are currently no plans to add this functionality, as the current use
case (displaying permissions to the user for editing) only applies to direct
relationships.
