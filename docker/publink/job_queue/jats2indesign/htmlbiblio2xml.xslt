<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
                xmlns:html="http://www.w3.org/1999/xhtml">
    <xsl:output method="xml" indent="yes"/>
    
    <xsl:template match="/html:html">
        <back>
        <ref-list>
            <xsl:apply-templates select="html:body/html:div/html:div[@class='csl-entry']"/>
        </ref-list>
        </back>
    </xsl:template>
    
    <xsl:template match="html:div[@class='csl-entry']">
        <biblioitem>
         
         <xsl:apply-templates/>
            
        </biblioitem>
        <xsl:text>&#x2029;</xsl:text>
    </xsl:template>
    <xsl:template match="html:div[@class='csl-block']">
        <csl-block>
                 <xsl:value-of select="."/>
        </csl-block><xsl:text>&#x2028;</xsl:text>
    </xsl:template>
   
    <xsl:template match="html:em">
        <i><xsl:value-of select="."/></i>
    </xsl:template>
</xsl:stylesheet>