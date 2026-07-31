<xsl:stylesheet version="2.0"
                xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
     <xsl:output method="xml" indent="yes" doctype-public="-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.3 20210610//EN" doctype-system="https://jats.nlm.nih.gov/publishing/1.3/JATS-journalpublishing1-3.dtd" />

    <!-- Identity transform for nodes other than the parent of ref elements -->
    <xsl:template match="@*|node()">
        <xsl:copy>
            <xsl:apply-templates select="@*|node()"/>
        </xsl:copy>
    </xsl:template>

    <!-- Template to process the parent of ref elements and sort them based on their labels -->
    <xsl:template match="*[ref]"> <!-- Adjust this match pattern to target the specific parent of ref elements -->
        <xsl:copy>
            <xsl:apply-templates select="@*"/>
            <xsl:apply-templates select="ref">
                <xsl:sort select="(//xref[@ref-type='bibr' and @rid=current()/@id])[1]" 
                          data-type="text" order="ascending"/>
            </xsl:apply-templates>
        </xsl:copy>
    </xsl:template>

    <!-- Template for ref elements to include labels for sorting -->
    <xsl:template match="ref">
        <xsl:variable name="currentId" select="@id"/>
        <xsl:variable name="firstLabel" select="(//xref[@ref-type='bibr' and @rid=$currentId])[1]"/>
        <xsl:copy>
            <xsl:apply-templates select="@*"/>
            <label>
                <xsl:choose>
                    <xsl:when test="contains($firstLabel, ',')">
                        <xsl:value-of select="substring-before($firstLabel, ',')"/>
                    </xsl:when>
                    <xsl:otherwise>
                        <xsl:value-of select="$firstLabel"/>
                    </xsl:otherwise>
                </xsl:choose>
            </label>
            <xsl:apply-templates select="node()"/>
        </xsl:copy>
    </xsl:template>
        <!-- Template to change publication-type="" to publication-type="webpage" -->
    <xsl:template match="element-citation[not(@publication-type) or @publication-type='']">
        <xsl:copy>
            <xsl:attribute name="publication-type">webpage</xsl:attribute>
            <xsl:apply-templates select="@*[name()!='publication-type']|node()"/>
        </xsl:copy>
    </xsl:template>

   <!-- Template to transform article-title to source within book citations -->
    <xsl:template match="element-citation[@publication-type='book' or @publication-type='thesis'or @publication-type='motion_picture']/article-title">
        <source>
            <xsl:apply-templates select="@*|node()"/>
        </source>
    </xsl:template>

        <!-- Template to transform article-title to part-title within chapter or paper-conference citations -->
    <xsl:template match="element-citation[@publication-type='chapter' or @publication-type='paper-conference' or @publication-type='webpage']/article-title">
        <part-title>
            <xsl:apply-templates select="@*|node()"/>
        </part-title>
    
    </xsl:template>
    
    
</xsl:stylesheet>
