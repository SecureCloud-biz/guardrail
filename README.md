# Guardrail - A PHP Static Analysis tool
Copyright (c) 2017 Jon Gardiner and BambooHR

## Introduction

Guardrail is a static analysis engine for PHP.  Guardrail will index your code base, learn
every symbol, and then confirm that every file in the system uses those symbols in a way that
makes sense.  For example, if you have a function call to an undefined function, it will be
found by Guardrail.

Guardrail is not proof that your code is perfect or even semantically valid.  You should never 
use Guardrail as an excuse not to write unit tests.  Rather, it is a final layer of protection to 
give confidence that preventable mistakes, syntax errors, or typos do not occur.  You can think 
of Guardrail like the guardrails along a highway, you never want to hit one, but you're glad to 
know they are there.

At BambooHR we are big believers in continuous integration.  We use Guardrail inside our open 
sourced CI tool, Rapid. (See https://github.com/BambooHR/rapid)  This is done in addition to a
healthy set of unit and integration tests that we also run against all layers of our stack.

Guardrail uses Nikita Popov's excellent PHP parser library. (See https://github.com/nikic/PHP-Parser)

## The need for a tool like Guardrail in PHP

According to W3Techs (https://w3techs.com/technologies/overview/programming_language/all) in 2017 PHP 
is running on 82% of all the sites whose server-side language they can determine.  Other documentation
confirms that a vast majority of dynamic content on the Internet is served from PHP.  Facebook powers
such massive sites as Facebook and Wikipedia.

Often these sites start from a small home grown code base, a Wordpress install, or a few customizations 
on top of a framework.  These are great options that play to the strengths of PHP.  You can quickly 
prop up a website and prove the business model before you spend a lot of time and money worrying 
about enterprise scale.  The PHP language performs reasonably well and is very quick to develop 
with.    The language is very forgiving, has a very mature library ecosystem with Composer, several robust frameworks, 
and broad hosting availability.

For a small website PHP works exceedingly well.  If you are lucky enough to have a formerly small website that 
has grown up, you will start to run into difficulties dealing with large code base in PHP.  Many of these 
complications are due to the lack of enforcements of contracts in the language.  If you choose to do so, you
can pass around arbitrary objects of any type to any method.  There are also artifacts dating back to the
days when PHP didn't support object oriented programming particularly well.

Guardrail is a tool that allows developers that want to be more rigorous than stock PHP requires.  It can be
applied to any code base.  

   
## Supported checks
 
 Guardrail currently implements checks for the following:
 
 - Unsafe shell commands (backtick, exec, passthru )
 - Switch statements that don't "break" at the bottom of each "case".  
 - References to undefined objects (new Foo(), Foo::method())
 - References to undefined constants
 - References to undefined class constants
 - Classes that don't implement all the methods of an interface.
 - Method and function calls that don't pass enough parameters or pass to many parameters.
 - Type mismatches for type-hinted parameters.
 - Property fetches for members that were not previously declared.
 - Catching exceptions that don't exist.
 
 Additionally, a simple plugin system exists that allows you to register node visitors for 
 the abstract syntax tree for to enable additional checks. At BambooHR, we use this plugin mechanism
 to run some additional checks that are only relevant to our stack.
 
 ## Installation
 
 Guardrail is available as a composer packaged BambooHR/Guardrail.
 
 It will install itself in vendor/bin/guardrail.php.
 
 You can also package Guardrail as a .phar file by running Build.sh
   which is found in vendor/bamboohr/guardrail/src/bin.
 
## Usage
 
There are two phases of execution in Guardrail: indexing and analysis.

### Indexing
The indexing phase can only be run in a single process.  A moderately large
code base including all vendor libraries can take a few minutes to index.

### Analysis
One the index is produced, the analysis can be run.  Analysis is heavily CPU bound.  
It can be run across multiple processes or even multiple machines.  When 
run across multiple machines, you will need to gather the output from all of
them to review the results.  (BambooHR uses Rapid to automate this.)

### Configuration

Guardrail configuration consists of 6 sections:
  index, ignore, test, test-ignore, emit, and plugins.
  
The *index* section is a list of subdirectories to index.
 The *ignore* section is a list of file paths to ignore from indexing.  The 
 ignore section can use globbing patterns include double asterisks
 to indicate any number of directories.    
 
 These two sections work together to determine what files will be indexed.  
 Any file listed under an index directory, but not excluded by an *ignore* block
 will be indexed.  It is important to index as much of your code base as possible 
 because otherwise it will not be possible to resolve includes.
 
 The *test* section is a list of directories to run the analysis phase on.  
 The *test-ignore* is a list of file paths to ignore from analysis.  This section
 can also use globbing patterns to ignore multiple files at once.
 
 The *emit* section is used to control which errors are reported.  Most
 code bases will not pass with all of the standard checks emitted.  We
 recommend adding a single check at a time and incrementally improving
 your codebase until all tests pass.
 
 The *plugins* section is a lot of plugins to use in the analysis.
 Plugins allow you to extend Guardrail with your own checks.  
 

Sample config file:

```json
{
    "index": [
        "app",
        "vendor",
        "/usr/share/php"
    ],
    "ignore": [
             "**/vendor/**/tests/**/*",
             "**/vendor/**/Tests/**/*"
    ],
    "test": [
         "app/html",
         "app/includes"
     ],
    "test-ignore": [ 
        "**/vendor/**/*" 
    ],
    "emit":
    [
        "Standard.Unknown.Class",
        "Standard.Unknown.Class.Constant",
        "Standard.Unknown.Function",
        "Standard.Unknown.Variable",

        "Standard.Inheritance",
        "Standard.Inheritance.Php7",
        "Standard.Inheritance.Unimplemented",
 
        "Standard.Scope",
        "Standard.Param.Count",
        "Standard.Param.Type",

        "Standard.Switch.Break",
        "Standard.Parse.Error",
        
        "BambooHR.Impossible.Inject"
    ], 
    "plugins": [
        "plugins/guardrail/ImpossibleInjectionCheck.php"
    ]
}
```

### Command line

<pre>
Usage: php -d memory_limit=500M vendor/bin/guardrail.php [-a] [-i] [-n #] [-o output_file_name] [-p #/#] config_file

where: -p #/#                 = Define the number of partitions and the current partition.
                                Use for multiple hosts. Example: -p 1/4

       -n #                   = number of child process to run.
                                Use for multiple processes on a single host.

       -a                     = run the "analyze" operation

       -i                     = run the "index" operation.
                                Defaults to yes if using in memory index.

       -s                     = prefer sqlite index

       -m                     = prefer in memory index (only available when -n=1 and -p=1/1)

       -o output_file_name    = Output results in junit format to the specified filename

       -v                     = Increase verbosity level.  Can be used once or twice.

       -h  or --help          = Ignore all other options and show this page.


</pre>

To index all according to the config.json file, storing the index
in sqlite database, use the following command line.

`php vendor/bin/guardrail.php -i -s config.json`

To run the analysis 

`php vendor/bin/guardrail.php -a -s config.json`

If you want to see progress during either the index or analysis phase
use -v to enable verbose output.

By default, a report is output in Xunit format to standard out.  If you
would prefer to output to a file use -o to specify an output filename.

# Links

[Plugin architecture](docs/plugins.md)
 
 

 






