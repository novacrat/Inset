Inset
=====

One-file HTML template parser for PHP
 

# Introduction

If you seek to use the power of HTML templates within your PHP project but cannot use complex dependencies or don't want to include massive bunches of files of modern popular template engines, then this one is for you. 

**Inset** is a lightweight one-file PHP class that drives HTML templates right out-of-the-box. It was written in 2012 and works under PHP version 5.4 and above. It was inspired by Smarty/Twig-like syntax and supports commenting, variables, with several filters on top of them, conditional statements, for-cycles, and template embedding.

# Startup

For using Inset you just need to include `src/Inset.php` in your project and create a new `\Novacrat\Inset\Inset` object specifying a template file you want. 
Template will be tokenized and parsed. Just set some variables, and you are good to render it. 

~~~php
require_once '/src/Inset.php';
$tpl = new \Novacrat\Inset\Inset('template.html');
$tpl->setVar('foo', 'bar');
$tpl->setVars(array('name' => 'John', 'surname' => 'Doe'));
echo $tpl->render();
~~~


# Template Syntax

**Inset** uses three kind of block markers. `{# #}` for comments; `{{ }}` for variables output; and `{[ ]}` for control structures.
All newline characters around these markers will be omitted by default.


## Comments
All text between `{#` and `#}` markers will be considered as a comment and will be omitted in the rendered output.


## Variables
A variable label should be specified between `{{` and `}}` to be rendered.
If variable with specified label is set within template object it will be output. If not, nothing will be output.
Note that boolean variables rendered as empty strings.

## Filters

Any variable can be processed with a filter, which is specified with delimiter `|` followed right after variable. 
Multiple filters could be applied one after another. **Inset** supports several filters, described further.

~~~twig
{{ variable|striptags|upper }}
~~~

## Control structures

### Conditional statements

The conditional statement must start with block `{[ if condition ]}`, where _condition_ is a variable, that could be interpreted as a boolean.
Zero numbers, empty strings, or arrays are considered boolean false.

The conditional statement must end with block `{[ end if ]}`. Everything between them will be rendered in case the condition is met.
If a condition is not met, an optional block `{[ else ]}` could be used before `{[ and if ]}` for an alternate rendering.

~~~twig
{[ if condition ]}
output
{[ endif ]}

{[ if condition ]}
output
{[ else ]}
alternate output
{[ endif ]}
~~~

A condition could be not just interpreted as boolean, but also matched with a specified value, that is given after `:` marker, like `{[ if condition: variant1 ]}`
In this case, Additional variants of values for matches could be provided with block `{[ elseif condition: variant2 ]}`.

~~~twig
{[ if condition: variant1 ]}
output for variant 1
{[ if condition: variant2 ]}
output for variant 2
{[ if condition: variant3 ]}
output for variant 3
{[ else ]}
alternate output
{[ endif ]}
~~~

### Loop statement

**Inset** supports only one loop control structure, that iterates thru every value in array. 
A loop begins with block `{[ for item in items ]}`, where _items_ is variable holding array to iterate, and _item_ will hold a value from the array for every other iteration, and ends with block `{[ endfor ]}`. Everything betwen this blocks will be rendered as many times, as loop will be be iterated.
If given _items_ variable is not an array and holds a single value, it will be interpreted as an array of one element.

### With statement

With statement allows making variable labels aliases, using block `{[ with new_label as some_variable ]}`, 
where _some_variable_ will be aliased with _new_label_ until block `{[ endwith ]}`. Multiple aliases are separated with commas.

~~~twig
{[ with new_label as some_variable, other_new_labl as other_some_variable ]}
{{ new_label }}
{[ endwith ]}
~~~

### Embedding statements

Block `{[ embed 'filename.html' ]}` immediately embeds other template using the given filename. 
Optionally variables from the embedding template could be aliased with new labels, specified after delimiter `:`. Multiple aliases are separated with commas.

~~~twig
{[ embed 'filename.html' : new_label as some_variable, other_new_labl as other_some_variable ]}
~~~

You could also specify a point in the template where some content would be later inserted in PHP code. This is done with block `{[ slot name ]} `, where _name_ is a name for the slot. Use **Insert** method `set Filler` to specify that content, which is could be a string, another template, or a set of several of them.

## Filters

Supported filters:
Supported filters:
- `odd` - returns true if given value is odd.
- `even` - returns true if given value is even.
- `not` - returns the opposite for given boolean value.
- `title` - returns given string with every word capitalized.
- `capitalize` - returns given string with the first character capitalized.
- `upper` - returns given string uppercase.
- `lower` - returns given string lowercased.
- `strip tags` - strips all HTML and PHP tags from a given value.
- `escape` - convert special characters to HTML entities in a given string.
- `length` - returns the length of a given string or array.
- `fetch` - with a specified key, numeric or string, returns an element from a given array or object.
- `iteration` - returns current iteration counter for given iteration item.
- `key` - returns current iteration key for given iteration item.





