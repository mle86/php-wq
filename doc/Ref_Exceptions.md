# Exception classes

* <code>interface [mle86\WQ\Exception\\<b>WQException</b>](/src/WQ/Exception/WQException.php)</code>  
    All WQ Exceptions implement this empty interface.

    * <code>class [mle86\WQ\Exception\\<b>OptionValueException</b>](/src/WQ/Exception/OptionValueException.php) extends \InvalidArgumentException</code>  
        Thrown by `WorkProcessor::setOption` and `WorkProcessor::setOptions`
        in case of an invalid option value.

    * <code>class [mle86\WQ\Exception\\<b>UnserializationException</b>](/src/WQ/Exception/UnserializationException.php) extends \UnexpectedValueException</code>  
        Thrown by `QueueEntry::fromSerializedJob()`
        in case of invalid job data:
        - invalid serialization
        - non-object
        - object, but not a Job implementation

