# ACL Filter bridge for Doctrine QueryBuilder

* Cloned from rejsmont/LabDB.
* Extracted just the src/VIB/SecurityBundle package
* Removed and changed some files that weren't relevant to the ACL filter system.
* Renamed to ACLFilterBundle, to reflect singular purpose.
* Updated to work with Symfony 3

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

### Usage
